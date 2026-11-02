<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PwaAssetsTest extends TestCase
{
    public function test_manifest_is_served_with_correct_fields(): void
    {
        $response = $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJson([
                'name' => 'J.A.R.V.I.S.',
                'short_name' => 'JARVIS',
                'display' => 'standalone',
                'theme_color' => '#050a12',
                'background_color' => '#050a12',
                'start_url' => '/',
            ]);

        $icons = collect($response->json('icons'));

        foreach (['192x192', '512x512'] as $size) {
            $this->assertTrue(
                $icons->contains(fn (array $icon): bool => $icon['sizes'] === $size && $icon['purpose'] === 'any'),
                "Missing {$size} icon.",
            );
            $this->assertTrue(
                $icons->contains(fn (array $icon): bool => $icon['sizes'] === $size && $icon['purpose'] === 'maskable'),
                "Missing {$size} maskable icon.",
            );
        }

        $icons->each(function (array $icon): void {
            $this->assertSame('image/png', $icon['type']);
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));

            [$width, $height] = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame($icon['sizes'], "{$width}x{$height}");
        });
    }

    public function test_spa_shell_links_the_manifest(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<link rel="manifest" href="'.route('pwa.manifest').'">', false)
            ->assertSee('<meta name="theme-color" content="#050a12">', false);
    }

    public function test_service_worker_never_caches_api_routes(): void
    {
        $serviceWorker = $this->builtServiceWorker();

        // Backend paths go straight to the network.
        $this->assertMatchesRegularExpression(
            '#\(\{url:(\w+)\}\)=>/\^\\\\/\(api\|broadcasting\|sanctum\)\\\\/\|\^\\\\/admin/\.test\(\1\.pathname\),new class extends#',
            $serviceWorker,
        );
        $networkOnly = strpos($serviceWorker, '(api|broadcasting|sanctum)');
        $networkFirst = strpos($serviceWorker, '(api|admin|broadcasting|sanctum|livewire|filament|storage|build|up)');
        $this->assertNotFalse($networkFirst);
        $this->assertLessThan($networkFirst, $networkOnly, 'NetworkOnly must be registered before the shell route.');

        // NetworkFirst is reserved for SPA navigations (the HTML shell) and excludes backend paths.
        $this->assertSame(1, substr_count($serviceWorker, 'cacheName:"jarvis-shell"'));
        $this->assertMatchesRegularExpression('#"navigate"===\w+\.mode&&!/\^\\\\/\(api\|admin\|#', $serviceWorker);

        // No other runtime cache strategy exists (CacheFirst / StaleWhileRevalidate would carry their own cacheName).
        preg_match_all('/cacheName:"([^"]+)"/', $serviceWorker, $cacheNames);
        $this->assertSame(['jarvis-shell'], array_values(array_unique($cacheNames[1])));

        // The precache holds only build assets and icons — never API/admin/HTML responses.
        preg_match_all('/\{url:"([^"]+)",revision:/', $serviceWorker, $precached);
        $urls = $precached[1];

        $this->assertNotEmpty($urls);
        $this->assertContains('icons/icon-512.png', $urls);
        $this->assertNotEmpty(preg_grep('#^build/assets/app-.+\.js$#', $urls));
        $this->assertNotEmpty(preg_grep('#^build/assets/.+\.css$#', $urls));
        $this->assertNotEmpty(preg_grep('#^build/assets/.+\.woff2$#', $urls));

        foreach ($urls as $url) {
            $this->assertMatchesRegularExpression('#^(build/assets|icons)/#', $url);
            $this->assertDoesNotMatchRegularExpression('#(^|/)(api|broadcasting|sanctum|admin)(/|$)|\.html$#', $url);
        }
    }

    /**
     * Phase evidence: Lighthouse 11 (Chrome) PWA audit of the built app, kept in tests/Evidence/phase-17.
     */
    public function test_lighthouse_report_marks_the_app_as_installable(): void
    {
        $directory = base_path('tests/Evidence/phase-17');

        $this->assertFileExists("{$directory}/lighthouse-pwa.report.html");

        /** @var array{lighthouseVersion: string, audits: array<string, array{score: float|int|null, details?: array<string, mixed>}>, categories: array{pwa: array{score: float|int|null}}} $report */
        $report = json_decode(File::get("{$directory}/lighthouse-pwa.report.json"), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringStartsWith('11.', $report['lighthouseVersion']);
        $this->assertEquals(1, $report['audits']['installable-manifest']['score']);
        $this->assertSame([], $report['audits']['installable-manifest']['details']['items']);
        $this->assertSame('/manifest.webmanifest', parse_url($report['audits']['installable-manifest']['details']['debugData']['manifestUrl'], PHP_URL_PATH));

        foreach (['splash-screen', 'themed-omnibox', 'maskable-icon', 'viewport', 'content-width'] as $audit) {
            $this->assertEquals(1, $report['audits'][$audit]['score'], "Lighthouse audit {$audit} failed.");
        }

        $this->assertEquals(1, $report['categories']['pwa']['score']);
    }

    /**
     * Reads the worker generated by `npm run build` (vite-plugin-pwa); builds once when it is missing.
     */
    private function builtServiceWorker(): string
    {
        $path = public_path('sw.js');

        if (! File::exists($path)) {
            $build = Process::path(base_path())->timeout(300)->run('npm run build');

            $this->assertTrue($build->successful(), "npm run build failed:\n".$build->errorOutput());
        }

        $this->assertFileExists($path);

        return File::get($path);
    }
}
