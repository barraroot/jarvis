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
}
