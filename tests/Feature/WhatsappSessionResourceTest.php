<?php

namespace Tests\Feature;

use App\Filament\Resources\WhatsappSessions\Pages\ListWhatsappSessions;
use App\Filament\Resources\WhatsappSessions\WhatsappSessionResource;
use App\Models\AuditAction;
use App\Models\AuditLog;
use App\Models\ConnectorStatus;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOutboundMessage;
use App\Models\WhatsappOutboundRecipient;
use App\Models\WhatsappSession;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\CountsQueries;
use Tests\Concerns\FakesWuzapi;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class WhatsappSessionResourceTest extends TestCase
{
    use CountsQueries;
    use FakesWuzapi;
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->setUpSeedsLookups();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_admin_sees_columns_without_secrets_or_message_content(): void
    {
        app(Settings::class)->set('whatsapp.daily_send_cap', 150);

        $owner = User::factory()->create(['name' => 'Maria Operadora']);
        $session = WhatsappSession::factory()->for($owner)->create([
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::ERROR),
            'last_error' => 'Falha ao reconectar a sessão',
            'last_checked_at' => now()->subMinutes(5),
        ]);
        $suspended = WhatsappSession::factory()->connected()->suspended()->create();

        $this->sendToday($session, 3);
        $this->sendToday($session, 1, status: 'failed');
        $this->sendToday($session, 2, createdAt: now()->subDays(2));
        WhatsappMessage::factory()->forSession($session)->create(['text' => 'texto-secreto-da-conversa']);

        Livewire::test(ListWhatsappSessions::class)
            ->assertCanSeeTableRecords([$session, $suspended])
            ->assertTableColumnExists('user.name')
            ->assertTableColumnExists('status.slug')
            ->assertTableColumnExists('last_error')
            ->assertTableColumnExists('last_checked_at')
            ->assertTableColumnExists('sends_today_count')
            ->assertTableColumnExists('suspended_at')
            ->assertTableColumnFormattedStateSet('sends_today_count', '3 / 150', $session)
            ->assertTableColumnFormattedStateSet('status.slug', 'Erro', $session)
            ->assertTableActionVisible('suspend', $session)
            ->assertTableActionHidden('resume', $session)
            ->assertTableActionVisible('resume', $suspended)
            ->assertTableActionHidden('suspend', $suspended);

        $html = $this->get('/admin/whatsapp-sessions')->assertOk()->getContent();

        $this->assertStringContainsString('Maria Operadora', $html);
        $this->assertStringContainsString('Falha ao reconectar a sessão', $html);
        $this->assertStringContainsString('3 / 150', $html);

        foreach ([$session, $suspended] as $listed) {
            $listed->refresh();
            $this->assertStringNotContainsString($listed->token, $html);
            $this->assertStringNotContainsString($listed->hmac_key, $html);
            $this->assertStringNotContainsString($listed->webhook_key, $html);
            $this->assertStringNotContainsString($listed->webhook_key_hash, $html);
        }

        $this->assertStringNotContainsString('texto-secreto-da-conversa', $html);

        $this->assertFalse(WhatsappSessionResource::canCreate());
        $this->assertFalse(WhatsappSessionResource::canEdit($session));
        $this->assertFalse(WhatsappSessionResource::hasPage('edit'));
        $this->assertFalse(WhatsappSessionResource::hasPage('view'));
    }

    public function test_listing_never_selects_secrets_and_query_count_is_constant(): void
    {
        WhatsappSession::factory()->create();

        $loaded = WhatsappSessionResource::getEloquentQuery()->first();

        foreach (['token', 'hmac_key', 'webhook_key', 'webhook_key_hash'] as $secret) {
            $this->assertArrayNotHasKey($secret, $loaded->getAttributes());
        }

        // Warm the lookup and settings caches so only the listing itself is counted.
        $this->get('/admin/whatsapp-sessions')->assertOk();

        $single = $this->countQueries(fn () => $this->get('/admin/whatsapp-sessions')->assertOk());

        foreach (range(1, 4) as $index) {
            $this->sendToday(WhatsappSession::factory()->connected()->create(), $index);
        }

        $many = $this->countQueries(fn () => $this->get('/admin/whatsapp-sessions')->assertOk());

        $this->assertSame($single, $many);
    }

    public function test_non_admin_is_forbidden(): void
    {
        $operator = User::factory()->create();

        $this->actingAs($operator)->get('/admin/whatsapp-sessions')->assertForbidden();
    }

    public function test_suspend_disconnects_and_audits_and_resume_audits(): void
    {
        $this->fakeWuzapi();
        $session = WhatsappSession::factory()->connected()->create(['wuzapi_user_id' => 'wuzapi-user-1']);

        Livewire::test(ListWhatsappSessions::class)
            ->callTableAction('suspend', $session)
            ->assertHasNoTableActionErrors();

        $this->assertWuzapiCalled('POST', '/session/disconnect', 1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/session/disconnect')
            && $request->header('Token')[0] === $session->token);
        $this->assertTrue($session->refresh()->isSuspended());
        $this->assertSame(1, $this->auditCount(AuditAction::WHATSAPP_SESSION_SUSPENDED, $session));

        Livewire::test(ListWhatsappSessions::class)
            ->callTableAction('resume', $session)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($session->refresh()->isSuspended());
        $this->assertSame(1, $this->auditCount(AuditAction::WHATSAPP_SESSION_RESUMED, $session));
        $this->assertWuzapiCalled('POST', '/session/disconnect', 1);
    }

    private function sendToday(WhatsappSession $session, int $recipients, string $status = 'sent', mixed $createdAt = null): void
    {
        $outbound = WhatsappOutboundMessage::factory()->forSession($session)->{$status}()->create();

        foreach (range(1, $recipients) as $index) {
            WhatsappOutboundRecipient::query()->create([
                'whatsapp_outbound_message_id' => $outbound->id,
                'whatsapp_session_id' => $session->id,
                'jid' => "551198888000{$index}@s.whatsapp.net",
                'is_non_contact' => false,
                'created_at' => $createdAt ?? now(),
            ]);
        }
    }

    private function auditCount(string $action, WhatsappSession $session): int
    {
        return AuditLog::query()
            ->where('audit_action_id', AuditAction::idFor($action))
            ->where('subject_id', $session->id)
            ->count();
    }
}
