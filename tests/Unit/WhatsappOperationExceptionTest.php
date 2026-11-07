<?php

namespace Tests\Unit;

use App\Exceptions\WhatsappOperationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WhatsappOperationExceptionTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function codes(): array
    {
        return array_combine(WhatsappOperationException::CODES, array_map(fn (string $code): array => [$code], WhatsappOperationException::CODES));
    }

    public function test_declares_the_ten_ct31_codes(): void
    {
        $this->assertSame([
            'whatsapp_consent_required',
            'whatsapp_not_connected',
            'whatsapp_session_suspended',
            'whatsapp_rate_limited',
            'whatsapp_recipient_rate_limited',
            'whatsapp_daily_cap_reached',
            'whatsapp_mass_send_blocked',
            'whatsapp_kill_switch_active',
            'whatsapp_media_invalid',
            'whatsapp_upstream_error',
        ], WhatsappOperationException::CODES);
    }

    #[DataProvider('codes')]
    public function test_message_starts_with_code_followed_by_pt_br_text(string $code): void
    {
        $exception = new WhatsappOperationException($code);

        $translation = __('whatsapp.errors.'.$code, [], 'pt_BR');

        $this->assertNotSame('whatsapp.errors.'.$code, $translation, "Missing pt-BR text for [{$code}].");
        $this->assertSame($code.': '.$translation, $exception->getMessage());
        $this->assertSame($code, $exception->reason);
        $this->assertSame($translation, WhatsappOperationException::translate($code));
    }

    public function test_consent_required_lists_missing_scopes(): void
    {
        $exception = WhatsappOperationException::consentRequired(['envio', 'midia']);

        $this->assertSame(WhatsappOperationException::CONSENT_REQUIRED, $exception->reason);
        $this->assertSame(['envio', 'midia'], $exception->missingScopes);
        $this->assertStringStartsWith('whatsapp_consent_required: ', $exception->getMessage());
        $this->assertStringEndsWith('Escopos ausentes: envio, midia.', $exception->getMessage());
    }

    public function test_rate_limited_carries_retry_after(): void
    {
        $exception = WhatsappOperationException::rateLimited(12);

        $this->assertSame(12, $exception->retryAfter);
        $this->assertStringStartsWith('whatsapp_rate_limited: Limite de envios por minuto', $exception->getMessage());
        $this->assertStringContainsString('Tente novamente em 12 s', $exception->getMessage());
        $this->assertStringContainsString('retry_after=12', $exception->getMessage());

        $this->assertSame(1, WhatsappOperationException::rateLimited(0)->retryAfter);
    }

    public function test_unknown_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WhatsappOperationException('whatsapp_unknown');
    }
}
