<?php

namespace App\Tools\Internal\Concerns;

use App\Exceptions\WhatsappOperationException;
use App\Exceptions\WuzapiRequestException;
use App\Models\WhatsappSession;
use App\Services\WhatsappConsentService;
use App\Services\WuzapiClient;
use App\Support\Settings;
use App\Support\UntrustedContent;
use App\Tools\ToolContext;
use Closure;

/**
 * Shared guard of the `whatsapp.*` tools (CT-30). Every check runs before
 * any wuzapi call, in D8 order: consent and scopes (RF-10, RF-12, RF-13) →
 * session exists → not suspended (RF-41) → kill switch, only for tools that
 * write to WhatsApp (RF-26) → `connected`, only for tools that call wuzapi
 * (RF-17). The session is always the one of `$context->user`, never an id
 * taken from the model input (RF-16). Upstream failures become
 * `whatsapp_upstream_error` with the redacted detail neutralized (RF-18).
 */
trait InteractsWithWhatsapp
{
    use InteractsAsInternalTool;

    /**
     * Consent scopes the call needs; empty means any valid consent.
     *
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    abstract public function requiredScopes(array $input): array;

    /**
     * Whether the tool talks to wuzapi (requires a `connected` session).
     */
    abstract public function callsUpstream(): bool;

    /**
     * Whether the tool changes something on WhatsApp (blocked by the kill switch).
     */
    abstract public function writesToWhatsapp(): bool;

    /**
     * Run the D8 checks and return the caller's own session.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws WhatsappOperationException
     */
    public function guardInvocation(array $input, ToolContext $context): WhatsappSession
    {
        app(WhatsappConsentService::class)->assertAllows($context->user, $this->requiredScopes($input));

        $session = WhatsappSession::query()
            ->with('status')
            ->where('user_id', $context->user->id)
            ->first();

        if ($session === null) {
            throw new WhatsappOperationException(WhatsappOperationException::NOT_CONNECTED);
        }

        $session->setRelation('user', $context->user);

        if ($session->isSuspended()) {
            throw new WhatsappOperationException(WhatsappOperationException::SESSION_SUSPENDED);
        }

        if ($this->writesToWhatsapp() && app(Settings::class)->whatsappKillSwitchActive()) {
            throw new WhatsappOperationException(WhatsappOperationException::KILL_SWITCH_ACTIVE);
        }

        if ($this->callsUpstream() && ! $session->isConnected()) {
            throw new WhatsappOperationException(WhatsappOperationException::NOT_CONNECTED);
        }

        return $session;
    }

    /**
     * Call wuzapi with the session's client, translating its failures.
     *
     * @template TResult
     *
     * @param  Closure(WuzapiClient): TResult  $call
     * @return TResult
     *
     * @throws WhatsappOperationException
     */
    protected function callWuzapi(WhatsappSession $session, Closure $call): mixed
    {
        try {
            return $call(app(WuzapiClient::class)->forSession($session));
        } catch (WuzapiRequestException $exception) {
            throw WhatsappOperationException::upstreamError($exception->upstreamDetail);
        }
    }

    /**
     * Tool output is untrusted data: strip system-like markup (RNF-03).
     *
     * @param  array<array-key, mixed>  $output
     * @return array<array-key, mixed>
     */
    protected function untrusted(array $output): array
    {
        return UntrustedContent::neutralizeArray($output);
    }

    public function isWrite(): bool
    {
        return false;
    }

    public function isSensitive(): bool
    {
        return false;
    }
}
