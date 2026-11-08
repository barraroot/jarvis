<?php

namespace Tests\Feature;

use App\Exceptions\WhatsappOperationException;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Services\WhatsappConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappConsentServiceTest extends TestCase
{
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeWuzapi();

        $this->setUpSeedsLookups();
    }

    private function service(): WhatsappConsentService
    {
        return app(WhatsappConsentService::class);
    }

    public function test_grant_records_version_scopes_timestamp_and_one_audit(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create();

        $consent = $this->service()->grant($user, WhatsappConsent::CURRENT_VERSION, ['envio', 'leitura']);

        $consent->refresh();
        $this->assertSame(WhatsappConsent::CURRENT_VERSION, $consent->version);
        $this->assertSame(['leitura', 'envio'], $consent->scopes);
        $this->assertTrue($consent->consented_at->equalTo(now()));
        $this->assertNull($consent->revoked_at);
        $this->assertTrue($this->service()->isValid($user));

        $audits = AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor(AuditAction::WHATSAPP_CONSENT_GRANTED))
            ->get();
        $this->assertCount(1, $audits);
        $this->assertSame($user->id, $audits->first()->user_id);
        $this->assertEquals(['version' => WhatsappConsent::CURRENT_VERSION, 'scopes' => ['leitura', 'envio']], $audits->first()->properties);
    }

    public function test_grant_replaces_scopes_and_clears_revocation(): void
    {
        $user = User::factory()->create();
        WhatsappConsent::factory()->for($user)->revoked()->create();

        $this->service()->grant($user, WhatsappConsent::CURRENT_VERSION, ['grupos']);

        $consent = $this->service()->current($user);
        $this->assertSame(1, WhatsappConsent::query()->where('user_id', $user->id)->count());
        $this->assertSame(['grupos'], $consent->scopes);
        $this->assertNull($consent->revoked_at);
    }

    /**
     * @return array<string, array{int, array<array-key, mixed>, string}>
     */
    public static function invalidGrants(): array
    {
        return [
            'old version' => [WhatsappConsent::CURRENT_VERSION - 1, ['leitura'], 'version'],
            'future version' => [WhatsappConsent::CURRENT_VERSION + 1, ['leitura'], 'version'],
            'no scopes' => [WhatsappConsent::CURRENT_VERSION, [], 'scopes'],
            'unknown scope' => [WhatsappConsent::CURRENT_VERSION, ['leitura', 'admin'], 'scopes'],
            'repeated scope' => [WhatsappConsent::CURRENT_VERSION, ['envio', 'envio'], 'scopes'],
            'non-string scope' => [WhatsappConsent::CURRENT_VERSION, [1], 'scopes'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $scopes
     */
    #[DataProvider('invalidGrants')]
    public function test_grant_rejects_invalid_version_or_scopes(int $version, array $scopes, string $field): void
    {
        $user = User::factory()->create();

        try {
            $this->service()->grant($user, $version, $scopes);
            $this->fail('Expected ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }

        $this->assertNull($this->service()->current($user));
        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_outdated_consent_is_invalid_and_lacks_every_scope(): void
    {
        $user = User::factory()->create();
        WhatsappConsent::factory()->for($user)->outdated()->create();

        $this->assertFalse($this->service()->isValid($user));

        try {
            $this->service()->assertAllows($user, WhatsappConsent::SCOPES);
            $this->fail('Expected WhatsappOperationException.');
        } catch (WhatsappOperationException $exception) {
            $this->assertSame(WhatsappOperationException::CONSENT_REQUIRED, $exception->reason);
            $this->assertSame(WhatsappConsent::SCOPES, $exception->missingScopes);
            $this->assertStringStartsWith('whatsapp_consent_required:', $exception->getMessage());
        }
    }

    public function test_absent_or_revoked_consent_fails(): void
    {
        $absent = User::factory()->create();
        $revoked = User::factory()->create();
        WhatsappConsent::factory()->for($revoked)->revoked()->create();

        foreach ([$absent, $revoked] as $user) {
            $this->assertFalse($this->service()->isValid($user));

            try {
                $this->service()->assertAllows($user, ['leitura']);
                $this->fail('Expected WhatsappOperationException.');
            } catch (WhatsappOperationException $exception) {
                $this->assertSame(['leitura'], $exception->missingScopes);
            }
        }
    }

    public function test_removing_a_scope_applies_immediately(): void
    {
        $user = User::factory()->create();
        $this->service()->grant($user, WhatsappConsent::CURRENT_VERSION, ['leitura', 'envio']);
        $this->service()->assertAllows($user, ['envio']);

        $this->service()->grant($user, WhatsappConsent::CURRENT_VERSION, ['leitura']);

        $this->service()->assertAllows($user, ['leitura']);

        try {
            $this->service()->assertAllows($user, ['leitura', 'envio']);
            $this->fail('Expected WhatsappOperationException.');
        } catch (WhatsappOperationException $exception) {
            $this->assertSame(['envio'], $exception->missingScopes);
        }
    }

    public function test_mark_revoked_invalidates_consent(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        WhatsappConsent::factory()->for($user)->create();
        WhatsappConsent::factory()->for($other)->create();

        $this->service()->markRevoked($user);

        $this->assertNotNull($this->service()->current($user)->revoked_at);
        $this->assertFalse($this->service()->isValid($user));
        $this->assertTrue($this->service()->isValid($other));
    }
}
