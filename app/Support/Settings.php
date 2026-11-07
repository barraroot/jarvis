<?php

namespace App\Support;

use App\Models\AuditAction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Typed access to the global `settings` table (US-12.2), cached per key.
 */
class Settings
{
    public const string CACHE_PREFIX = 'settings:';

    /**
     * Editable keys with their accepted range (US-12.2). `max` null means unbounded;
     * `type` decides how the submitted value is cast before persisting.
     *
     * @var array<string, array{min: int|float, max: int|float|null, type: 'int'|'float'}>
     */
    public const array RANGES = [
        'brain.max_delegation_depth' => ['min' => 0, 'max' => 5, 'type' => 'int'],
        'brain.tool_timeout_seconds' => ['min' => 5, 'max' => 300, 'type' => 'int'],
        'brain.max_tool_calls' => ['min' => 1, 'max' => 100, 'type' => 'int'],
        'brain.task_timeout_seconds' => ['min' => 60, 'max' => 3600, 'type' => 'int'],
        'brain.messages_per_minute' => ['min' => 1, 'max' => 600, 'type' => 'int'],
        'brain.approval_ttl_hours' => ['min' => 1, 'max' => 168, 'type' => 'int'],
        'brain.context_messages' => ['min' => 2, 'max' => 100, 'type' => 'int'],
        'memory.top_n' => ['min' => 1, 'max' => 20, 'type' => 'int'],
        'memory.similarity_threshold' => ['min' => 0.5, 'max' => 0.99, 'type' => 'float'],
        'memory.duplicate_threshold' => ['min' => 0.5, 'max' => 0.99, 'type' => 'float'],
        'connector.health_check_minutes' => ['min' => 1, 'max' => 1440, 'type' => 'int'],
        'retention.tool_executions_days' => ['min' => 1, 'max' => null, 'type' => 'int'],
        'retention.task_logs_days' => ['min' => 1, 'max' => null, 'type' => 'int'],
        'retention.usage_records_months' => ['min' => 1, 'max' => null, 'type' => 'int'],
        'retention.read_notifications_days' => ['min' => 1, 'max' => null, 'type' => 'int'],
        'voice.listening_window_seconds' => ['min' => 15, 'max' => 300, 'type' => 'int'],
        'voice.session_token_ceiling' => ['min' => 10000, 'max' => 2000000, 'type' => 'int'],
        'whatsapp.kill_switch' => ['min' => 0, 'max' => 1, 'type' => 'int'],
        'whatsapp.max_sends_per_minute' => ['min' => 2, 'max' => 30, 'type' => 'int'],
        'whatsapp.max_sends_per_recipient_per_hour' => ['min' => 3, 'max' => 50, 'type' => 'int'],
        'whatsapp.daily_send_cap' => ['min' => 50, 'max' => 1000, 'type' => 'int'],
        'whatsapp.min_send_interval_seconds' => ['min' => 2, 'max' => 40, 'type' => 'int'],
        'whatsapp.jitter_min_seconds' => ['min' => 1, 'max' => 10, 'type' => 'int'],
        'whatsapp.jitter_max_seconds' => ['min' => 2, 'max' => 35, 'type' => 'int'],
        'whatsapp.typing_min_ms' => ['min' => 375, 'max' => 7500, 'type' => 'int'],
        'whatsapp.typing_max_ms' => ['min' => 1500, 'max' => 30000, 'type' => 'int'],
        'whatsapp.max_non_contact_recipients' => ['min' => 2, 'max' => 25, 'type' => 'int'],
        'whatsapp.non_contact_window_minutes' => ['min' => 15, 'max' => 300, 'type' => 'int'],
        'retention.whatsapp_messages_days' => ['min' => 1, 'max' => null, 'type' => 'int'],
    ];

    /**
     * Pairs of keys whose values must stay ordered (`min` ≤ `max`) — WhatsApp
     * jitter and typing windows (CT-40).
     *
     * @var list<array{min: string, max: string}>
     */
    public const array CROSS_RULES = [
        ['min' => 'whatsapp.jitter_min_seconds', 'max' => 'whatsapp.jitter_max_seconds'],
        ['min' => 'whatsapp.typing_min_ms', 'max' => 'whatsapp.typing_max_ms'],
    ];

    /**
     * Defaults of the WhatsApp anti-block settings (CT-40), used while no
     * value is stored.
     *
     * @var array<string, int>
     */
    public const array WHATSAPP_DEFAULTS = [
        'whatsapp.kill_switch' => 0,
        'whatsapp.max_sends_per_minute' => 6,
        'whatsapp.max_sends_per_recipient_per_hour' => 10,
        'whatsapp.daily_send_cap' => 200,
        'whatsapp.min_send_interval_seconds' => 8,
        'whatsapp.jitter_min_seconds' => 2,
        'whatsapp.jitter_max_seconds' => 7,
        'whatsapp.typing_min_ms' => 1500,
        'whatsapp.typing_max_ms' => 6000,
        'whatsapp.max_non_contact_recipients' => 5,
        'whatsapp.non_contact_window_minutes' => 60,
        'retention.whatsapp_messages_days' => 30,
    ];

    /**
     * Editable keys restricted to a closed list of string values (CT-12).
     *
     * @var array<string, list<string>>
     */
    public const array OPTIONS = [
        'voice.realtime_voice' => ['alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse', 'marin', 'cedar'],
    ];

    /**
     * Read a setting; the stored JSON value is returned as-is (scalar or array).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        /** @var array{found: bool, value: mixed} $cached */
        $cached = Cache::rememberForever(self::CACHE_PREFIX.$key, function () use ($key): array {
            $setting = Setting::query()->where('key', $key)->first();

            return ['found' => $setting !== null, 'value' => $setting?->value];
        });

        return $cached['found'] ? $cached['value'] : $default;
    }

    /**
     * Persist a setting, record who changed it and bust its cache entry.
     */
    public function set(string $key, mixed $value, ?User $by = null): Setting
    {
        $setting = Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by_user_id' => $by?->id],
        );

        $this->forget($key);

        return $setting;
    }

    /**
     * Persist every changed value (cast per `RANGES`, or checked against `OPTIONS`),
     * auditing each change as `setting_updated` with old/new values. Unknown keys,
     * values outside `OPTIONS` and unchanged keys are skipped.
     *
     * @param  array<string, mixed>  $values
     * @return list<string> keys that actually changed
     */
    public function update(array $values, User $by): array
    {
        $changed = [];

        foreach ($values as $key => $value) {
            $new = $this->normalize($key, $value);

            if ($new === null) {
                continue;
            }

            $old = $this->get($key);

            if ($old !== null && $old == $new) {
                continue;
            }

            $setting = $this->set($key, $new, $by);

            app(AuditLogger::class)->log(AuditAction::SETTING_UPDATED, $by, $setting, [
                'key' => $key,
                'old' => $old,
                'new' => $new,
            ]);

            $changed[] = $key;
        }

        return $changed;
    }

    /**
     * Cross-field violations of `CROSS_RULES` for the submitted values, falling
     * back to the stored (or default) value of keys not submitted. Errors are
     * keyed by the `max` key of each violated pair, in pt-BR.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function crossFieldErrors(array $values): array
    {
        $errors = [];

        foreach (self::CROSS_RULES as $rule) {
            $min = (int) ($values[$rule['min']] ?? $this->get($rule['min'], self::WHATSAPP_DEFAULTS[$rule['min']] ?? null));
            $max = (int) ($values[$rule['max']] ?? $this->get($rule['max'], self::WHATSAPP_DEFAULTS[$rule['max']] ?? null));

            if ($min > $max) {
                $errors[$rule['max']] = __('whatsapp.settings.cross_field', ['min' => $rule['min'], 'max' => $rule['max']]);
            }
        }

        return $errors;
    }

    /**
     * Cast a submitted value to its stored form, or null when the key is not
     * editable or the value is not one of its accepted options.
     */
    private function normalize(string $key, mixed $value): int|float|string|null
    {
        if (array_key_exists($key, self::RANGES)) {
            return self::RANGES[$key]['type'] === 'float' ? (float) $value : (int) $value;
        }

        if (array_key_exists($key, self::OPTIONS)) {
            return is_string($value) && in_array($value, self::OPTIONS[$key], true) ? $value : null;
        }

        return null;
    }

    /**
     * Drop the cached entry for a key (or every key when null).
     */
    public function forget(?string $key = null): void
    {
        if ($key !== null) {
            Cache::forget(self::CACHE_PREFIX.$key);

            return;
        }

        Setting::query()->pluck('key')->each(fn (string $key) => Cache::forget(self::CACHE_PREFIX.$key));
    }

    public function maxDelegationDepth(): int
    {
        return (int) $this->get('brain.max_delegation_depth', 3);
    }

    public function toolTimeoutSeconds(): int
    {
        return (int) $this->get('brain.tool_timeout_seconds', 30);
    }

    public function maxToolCalls(): int
    {
        return (int) $this->get('brain.max_tool_calls', 20);
    }

    public function taskTimeoutSeconds(): int
    {
        return (int) $this->get('brain.task_timeout_seconds', 900);
    }

    public function messagesPerMinute(): int
    {
        return (int) $this->get('brain.messages_per_minute', 60);
    }

    public function approvalTtlHours(): int
    {
        return (int) $this->get('brain.approval_ttl_hours', 24);
    }

    public function memoryTopN(): int
    {
        return (int) $this->get('memory.top_n', 5);
    }

    public function memorySimilarityThreshold(): float
    {
        return (float) $this->get('memory.similarity_threshold', 0.75);
    }

    public function memoryDuplicateThreshold(): float
    {
        return (float) $this->get('memory.duplicate_threshold', 0.92);
    }

    public function contextMessages(): int
    {
        return (int) $this->get('brain.context_messages', 20);
    }

    public function connectorHealthCheckMinutes(): int
    {
        return (int) $this->get('connector.health_check_minutes', 5);
    }

    public function toolExecutionsRetentionDays(): int
    {
        return (int) $this->get('retention.tool_executions_days', 90);
    }

    public function taskLogsRetentionDays(): int
    {
        return (int) $this->get('retention.task_logs_days', 90);
    }

    public function usageRecordsRetentionMonths(): int
    {
        return (int) $this->get('retention.usage_records_months', 24);
    }

    public function readNotificationsRetentionDays(): int
    {
        return (int) $this->get('retention.read_notifications_days', 30);
    }

    public function listeningWindowSeconds(): int
    {
        return (int) $this->get('voice.listening_window_seconds', 60);
    }

    public function sessionTokenCeiling(): int
    {
        return (int) $this->get('voice.session_token_ceiling', 200000);
    }

    public function realtimeVoice(): string
    {
        $voice = $this->get('voice.realtime_voice');

        return is_string($voice) && in_array($voice, self::OPTIONS['voice.realtime_voice'], true) ? $voice : 'marin';
    }

    public function whatsappKillSwitchActive(): bool
    {
        return (int) $this->get('whatsapp.kill_switch', self::WHATSAPP_DEFAULTS['whatsapp.kill_switch']) === 1;
    }

    public function whatsappMaxSendsPerMinute(): int
    {
        return (int) $this->get('whatsapp.max_sends_per_minute', self::WHATSAPP_DEFAULTS['whatsapp.max_sends_per_minute']);
    }

    public function whatsappMaxSendsPerRecipientPerHour(): int
    {
        return (int) $this->get('whatsapp.max_sends_per_recipient_per_hour', self::WHATSAPP_DEFAULTS['whatsapp.max_sends_per_recipient_per_hour']);
    }

    public function whatsappDailySendCap(): int
    {
        return (int) $this->get('whatsapp.daily_send_cap', self::WHATSAPP_DEFAULTS['whatsapp.daily_send_cap']);
    }

    public function whatsappMinSendIntervalSeconds(): int
    {
        return (int) $this->get('whatsapp.min_send_interval_seconds', self::WHATSAPP_DEFAULTS['whatsapp.min_send_interval_seconds']);
    }

    public function whatsappJitterMinSeconds(): int
    {
        return (int) $this->get('whatsapp.jitter_min_seconds', self::WHATSAPP_DEFAULTS['whatsapp.jitter_min_seconds']);
    }

    public function whatsappJitterMaxSeconds(): int
    {
        return (int) $this->get('whatsapp.jitter_max_seconds', self::WHATSAPP_DEFAULTS['whatsapp.jitter_max_seconds']);
    }

    public function whatsappTypingMinMs(): int
    {
        return (int) $this->get('whatsapp.typing_min_ms', self::WHATSAPP_DEFAULTS['whatsapp.typing_min_ms']);
    }

    public function whatsappTypingMaxMs(): int
    {
        return (int) $this->get('whatsapp.typing_max_ms', self::WHATSAPP_DEFAULTS['whatsapp.typing_max_ms']);
    }

    public function whatsappMaxNonContactRecipients(): int
    {
        return (int) $this->get('whatsapp.max_non_contact_recipients', self::WHATSAPP_DEFAULTS['whatsapp.max_non_contact_recipients']);
    }

    public function whatsappNonContactWindowMinutes(): int
    {
        return (int) $this->get('whatsapp.non_contact_window_minutes', self::WHATSAPP_DEFAULTS['whatsapp.non_contact_window_minutes']);
    }

    public function whatsappMessagesRetentionDays(): int
    {
        return (int) $this->get('retention.whatsapp_messages_days', self::WHATSAPP_DEFAULTS['retention.whatsapp_messages_days']);
    }
}
