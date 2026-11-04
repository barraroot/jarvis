<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_spa_responses_include_csp_and_nosniff(): void
    {
        config([
            'broadcasting.connections.reverb.options.host' => 'ws.jarvis.test',
            'broadcasting.connections.reverb.options.port' => 443,
            'broadcasting.connections.reverb.options.scheme' => 'https',
            'services.reverb.public.host' => null,
        ]);

        foreach (['/', '/conversas/42', '/configuracoes'] as $path) {
            $response = $this->get($path)->assertOk();

            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

            $csp = (string) $response->headers->get('Content-Security-Policy');

            $this->assertStringContainsString("default-src 'self'", $csp, $path);
            $this->assertStringContainsString("frame-ancestors 'none'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertMatchesRegularExpression("/connect-src 'self'[^;]* wss:\/\/ws\.jarvis\.test(;|$| )/", $csp);
            $this->assertMatchesRegularExpression('/font-src [^;]*'.preg_quote(SecurityHeaders::BUNNY_FONTS, '/').'/', $csp);
            $this->assertMatchesRegularExpression('/style-src [^;]*'.preg_quote(SecurityHeaders::BUNNY_FONTS, '/').'/', $csp);
            $this->assertStringNotContainsString("'unsafe-eval'", $csp);
            $this->assertDoesNotMatchRegularExpression("/script-src [^;]*'unsafe-inline'/", $csp);
        }
    }

    public function test_reverb_on_custom_port_is_allowed_over_ws(): void
    {
        config([
            'broadcasting.connections.reverb.options.host' => 'localhost',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'services.reverb.public.host' => null,
        ]);

        $this->assertStringContainsString(
            'ws://localhost:8080',
            (string) $this->get('/')->headers->get('Content-Security-Policy'),
        );
    }

    public function test_public_reverb_address_is_allowed_instead_of_the_internal_host(): void
    {
        config([
            'broadcasting.connections.reverb.options.host' => 'reverb',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'services.reverb.public' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http'],
        ]);

        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('ws://localhost:8080', $csp);
        $this->assertStringNotContainsString('ws://reverb', $csp);
    }

    public function test_spa_csp_allows_realtime_origin_without_wildcards(): void
    {
        $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/connect-src [^;]*https:\/\/api\.openai\.com(;|$| )/', $csp);
        $this->assertStringNotContainsString('*', $csp);
    }

    public function test_invalid_realtime_origins_are_never_allowed(): void
    {
        foreach ([
            'https://*.openai.com',
            'http://api.openai.com',
            'https://api.openai.com/v1',
            'https://user:secret@api.openai.com',
            '*',
            '',
        ] as $origin) {
            config(['services.openai_realtime.origin' => $origin]);

            $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');

            preg_match('/connect-src ([^;]*)/', $csp, $matches);

            $this->assertStringNotContainsString('openai', $matches[1], "Origin [{$origin}] must be rejected.");
            $this->assertStringNotContainsString('*', $csp);
        }

        config(['services.openai_realtime.origin' => 'https://realtime.example.com:8443/']);

        $this->assertStringContainsString(
            'https://realtime.example.com:8443',
            (string) $this->get('/')->headers->get('Content-Security-Policy'),
        );
    }

    public function test_api_responses_include_nosniff_without_spa_csp(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/auth/me')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_session_cookie_is_same_site_lax_and_secure_in_production(): void
    {
        $this->assertSame('lax', config('session.same_site'));

        // The config default follows APP_ENV: production forces `Secure` unless overridden.
        $original = $_ENV['APP_ENV'] ?? null;
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        putenv('APP_ENV=production');

        try {
            $this->assertTrue((require base_path('config/session.php'))['secure']);
        } finally {
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $original ?? 'testing';
            putenv('APP_ENV='.($original ?? 'testing'));
        }
    }
}
