<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_logger_strips_secret_keys_from_properties(): void
    {
        $admin = User::factory()->create();
        $provider = AiProvider::factory()->create();

        $log = app(AuditLogger::class)->log(AuditAction::PROVIDER_UPDATED, $admin, $provider, [
            'name' => 'OpenAI',
            'api_key' => 'sk-secret',
            'credential' => 'top-secret',
            'password' => 'hunter2',
            'token' => 'abc',
            'API_KEY' => 'upper',
            'nested' => [
                'access_token' => 'nested-secret',
                'password_confirmation' => 'hunter2',
                'safe' => true,
            ],
            'changes' => ['base_url' => 'https://example.test'],
        ]);

        $this->assertSame([
            'name' => 'OpenAI',
            'nested' => ['safe' => true],
            'changes' => ['base_url' => 'https://example.test'],
        ], $log->properties);

        $serialized = json_encode(AuditLog::query()->findOrFail($log->id)->getRawOriginal('properties'));
        $this->assertStringNotContainsString('secret', $serialized);
        $this->assertStringNotContainsString('hunter2', $serialized);

        $this->assertTrue($log->user->is($admin));
        $this->assertTrue($log->subject->is($provider));
        $this->assertSame(AuditAction::PROVIDER_UPDATED, $log->action->slug);
    }

    public function test_logger_strips_whatsapp_secret_keys(): void
    {
        $this->assertSame(['session_id' => 7, 'nested' => ['safe' => true]], AuditLogger::sanitize([
            'session_id' => 7,
            'hmac_key' => 'hmac-secret',
            'webhook_key' => 'webhook-secret',
            'token' => 'wuzapi-token',
            'nested' => ['HMAC_KEY' => 'upper', 'webhook_key_hash' => 'hash', 'safe' => true],
        ]));

        $log = app(AuditLogger::class)->log(AuditAction::WHATSAPP_SESSION_PROVISIONED, User::factory()->create(), null, [
            'session_id' => 7,
            'hmac_key' => 'hmac-secret',
            'webhook_key' => 'webhook-secret',
        ]);

        $this->assertSame(['session_id' => 7], $log->properties);
        $this->assertStringNotContainsString('secret', (string) AuditLog::query()->findOrFail($log->id)->getRawOriginal('properties'));
    }

    public function test_logger_captures_request_ip_and_user_agent_and_allows_system_actor(): void
    {
        $request = Request::create('/admin', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_USER_AGENT' => 'JarvisTest/1.0',
        ]);
        $this->app->instance('request', $request);

        $log = app(AuditLogger::class)->log(AuditAction::APPROVAL_EXPIRED, null);

        $this->assertNull($log->user_id);
        $this->assertNull($log->subject_type);
        $this->assertNull($log->properties);
        $this->assertSame('203.0.113.7', $log->ip_address);
        $this->assertSame('JarvisTest/1.0', $log->user_agent);
        $this->assertNotNull($log->created_at);
    }
}
