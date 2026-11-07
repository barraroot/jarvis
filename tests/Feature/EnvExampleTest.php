<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Deploy hygiene (project-description §12): every environment key read by
 * `config/*.php` (and by the admin seeder) is declared in `.env.example` —
 * active or as a commented optional entry — and no development host is
 * hardcoded in the configuration.
 */
class EnvExampleTest extends TestCase
{
    /**
     * Keys read outside `config/` that operators must still provide.
     *
     * @var list<string>
     */
    private const array EXTRA_REQUIRED_KEYS = ['ADMIN_NAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD'];

    public function test_env_example_declares_every_env_key_used_in_config(): void
    {
        $used = [];

        foreach (File::files(config_path()) as $file) {
            preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", $file->getContents(), $matches);

            foreach ($matches[1] as $key) {
                $used[$key][] = $file->getFilename();
            }
        }

        $this->assertNotEmpty($used);

        $declared = $this->declaredKeys();

        $missing = array_diff([...array_keys($used), ...self::EXTRA_REQUIRED_KEYS], $declared);

        $this->assertSame([], array_values($missing), 'Missing from .env.example: '.implode(', ', $missing));
    }

    public function test_production_relevant_keys_are_active_entries(): void
    {
        $active = $this->declaredKeys(activeOnly: true);

        foreach ([
            'APP_KEY', 'APP_URL', 'APP_ENV', 'DB_CONNECTION', 'REDIS_HOST', 'QUEUE_CONNECTION', 'BROADCAST_CONNECTION',
            'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME',
            'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET',
            'ADMIN_NAME', 'ADMIN_EMAIL', 'ADMIN_PASSWORD', 'MCP_ALLOW_PRIVATE_HOSTS',
            'SANCTUM_STATEFUL_DOMAINS', 'FRONTEND_URL', 'SESSION_SECURE_COOKIE', 'SESSION_SAME_SITE',
            'WUZAPI_URL', 'WUZAPI_ADMIN_TOKEN', 'WUZAPI_WEBHOOK_BASE_URL',
        ] as $key) {
            $this->assertContains($key, $active, "{$key} must be an active entry in .env.example.");
        }
    }

    public function test_config_does_not_hardcode_development_hosts(): void
    {
        foreach (['cors.php', 'sanctum.php', 'services.php', 'broadcasting.php', 'reverb.php'] as $file) {
            $contents = File::get(config_path($file));

            $this->assertDoesNotMatchRegularExpression("/'[^']*(?:localhost:5173|127\.0\.0\.1:5173|laravel\.test|rustfs|mailpit|wuzapi:8080)[^']*'/", $contents, "{$file} hardcodes a development host.");
        }
    }

    /**
     * RNF-02: wuzapi stays on the internal `sail` network (no published
     * ports), is built from a pinned upstream tag and uses its own `wuzapi`
     * database on the existing pgsql service.
     */
    public function test_wuzapi_service_is_internal_pinned_and_uses_its_own_database(): void
    {
        $compose = Yaml::parseFile(base_path('compose.yaml'));
        $service = $compose['services']['wuzapi'];

        $this->assertArrayNotHasKey('ports', $service);
        $this->assertSame(['sail'], $service['networks']);
        $this->assertMatchesRegularExpression('~^https://github\.com/asternic/wuzapi\.git#(v\d+\.\d+\.\d+|[0-9a-f]{40})$~', $service['build']['context']);
        $this->assertSame('pgsql', $service['environment']['DB_HOST']);
        $this->assertSame('wuzapi', $service['environment']['DB_NAME']);
        $this->assertSame('json', $service['environment']['WEBHOOK_FORMAT']);
        $this->assertArrayHasKey('pgsql', $service['depends_on']);
        $this->assertArrayHasKey('sail-wuzapi', $compose['volumes']);
        $this->assertContains(
            './database/pgsql/create-wuzapi-database.sql:/docker-entrypoint-initdb.d/20-create-wuzapi-database.sql',
            $compose['services']['pgsql']['volumes'],
        );
        $this->assertStringContainsString('CREATE DATABASE wuzapi', File::get(base_path('database/pgsql/create-wuzapi-database.sql')));
    }

    /**
     * @return list<string>
     */
    private function declaredKeys(bool $activeOnly = false): array
    {
        $pattern = $activeOnly ? '/^([A-Z0-9_]+)=/m' : '/^#?\s*([A-Z0-9_]+)=/m';

        preg_match_all($pattern, File::get(base_path('.env.example')), $matches);

        return array_values(array_unique($matches[1]));
    }
}
