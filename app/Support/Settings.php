<?php

namespace App\Support;

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
}
