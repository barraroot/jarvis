<?php

namespace App\Services;

use App\Exceptions\RealtimeProviderException;
use App\Exceptions\RealtimeUnavailableException;
use App\Models\AiModel;
use App\Models\AiProviderDriver;
use App\Models\User;
use App\Models\VoiceSession;
use App\Support\Settings;
use App\Support\UntrustedContent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Issues the ephemeral OpenAI Realtime client secret for a voice session
 * (RF-09, RNF-02) through `POST /v1/realtime/client_secrets` (GA).
 *
 * The provider key never leaves the backend and the request payload is never
 * logged. Instructions carry the fixed pt-BR persona plus the untrusted-data
 * policy, and no user memories or RAG excerpts (RF-21a).
 */
class RealtimeSessionService
{
    public const string DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    /** RNF-02: an unused secret expires within 60 seconds (D7). */
    public const int CLIENT_SECRET_TTL_SECONDS = 60;

    public const string TRANSCRIPTION_MODEL = 'gpt-4o-mini-transcribe';

    public const int REQUEST_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly ModelSelector $models,
        private readonly Settings $settings,
    ) {}

    /**
     * Resolve the usable default Realtime model or fail as unavailable (409).
     */
    public function realtimeModel(): AiModel
    {
        $model = $this->models->defaultRealtimeModel();
        $provider = $model?->provider;

        if ($model === null
            || $provider === null
            || blank($provider->api_key)
            || $provider->driver?->slug !== AiProviderDriver::OPENAI) {
            throw new RealtimeUnavailableException;
        }

        return $model;
    }

    /**
     * @throws RealtimeUnavailableException when no usable Realtime model exists
     * @throws RealtimeProviderException when the provider refuses or cannot be reached
     */
    public function issue(User $user, VoiceSession $session, ?AiModel $model = null): RealtimeClientSecret
    {
        $model ??= $this->realtimeModel();
        $provider = $model->provider;
        $voice = $this->settings->realtimeVoice();
        $baseUrl = rtrim($provider->base_url ?: self::DEFAULT_BASE_URL, '/');

        try {
            $response = Http::withToken($provider->api_key)
                ->acceptJson()
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->post($baseUrl.'/realtime/client_secrets', $this->payload($model, $voice));
        } catch (ConnectionException $exception) {
            throw new RealtimeProviderException($exception);
        }

        $value = $response->json('value');
        $expiresAt = $response->json('expires_at');

        if ($response->failed() || ! is_string($value) || $value === '' || ! is_numeric($expiresAt)) {
            throw new RealtimeProviderException;
        }

        return new RealtimeClientSecret(
            value: $value,
            expiresAt: CarbonImmutable::createFromTimestamp((int) $expiresAt),
            model: $model,
            voice: $voice,
        );
    }

    /**
     * Fixed persona instructions with the untrusted-data policy (RF-13, RF-21a).
     */
    public function instructions(): string
    {
        return UntrustedContent::withPolicy(__('voice.instructions'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AiModel $model, string $voice): array
    {
        return [
            'expires_after' => [
                'anchor' => 'created_at',
                'seconds' => self::CLIENT_SECRET_TTL_SECONDS,
            ],
            'session' => [
                'type' => 'realtime',
                'model' => $model->model_identifier,
                'instructions' => $this->instructions(),
                'audio' => [
                    'input' => [
                        'transcription' => [
                            'model' => self::TRANSCRIPTION_MODEL,
                            'language' => 'pt',
                        ],
                        'turn_detection' => [
                            'type' => 'server_vad',
                            'create_response' => true,
                            'interrupt_response' => true,
                        ],
                    ],
                    'output' => [
                        'voice' => $voice,
                    ],
                ],
                'tools' => [$this->startTaskTool()],
                'tool_choice' => 'auto',
            ],
        ];
    }

    /**
     * Function tool the session calls to start a background task (RF-11, RF-19).
     *
     * @return array<string, mixed>
     */
    private function startTaskTool(): array
    {
        return [
            'type' => 'function',
            'name' => 'start_task',
            'description' => __('voice.tools.start_task.description'),
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'request' => [
                        'type' => 'string',
                        'description' => __('voice.tools.start_task.request'),
                    ],
                    'follow_up' => [
                        'type' => 'boolean',
                        'description' => __('voice.tools.start_task.follow_up'),
                    ],
                ],
                'required' => ['request', 'follow_up'],
                'additionalProperties' => false,
            ],
        ];
    }
}
