<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingsSeeder extends Seeder
{
    /**
     * Default global settings (US-12.2). Existing keys are never overwritten.
     *
     * @return array<string, array{value: int|float|string, description: string}>
     */
    public static function defaults(): array
    {
        return [
            'brain.max_delegation_depth' => ['value' => 3, 'description' => 'Maximum depth of agent-to-agent delegation.'],
            'brain.tool_timeout_seconds' => ['value' => 30, 'description' => 'Default timeout for a single tool execution.'],
            'brain.max_tool_calls' => ['value' => 20, 'description' => 'Maximum tool calls per agent execution.'],
            'brain.task_timeout_seconds' => ['value' => 900, 'description' => 'Default timeout for a background task.'],
            'brain.messages_per_minute' => ['value' => 60, 'description' => 'Rate limit of messages per user per minute.'],
            'brain.approval_ttl_hours' => ['value' => 24, 'description' => 'Hours before a pending approval expires.'],
            'memory.top_n' => ['value' => 5, 'description' => 'Number of memories retrieved per request.'],
            'memory.similarity_threshold' => ['value' => 0.75, 'description' => 'Minimum cosine similarity for a memory to be relevant.'],
            'memory.duplicate_threshold' => ['value' => 0.92, 'description' => 'Cosine similarity above which a memory is considered a duplicate.'],
            'brain.context_messages' => ['value' => 20, 'description' => 'Number of recent messages sent as short-term context.'],
            'connector.health_check_minutes' => ['value' => 5, 'description' => 'Interval between MCP connector health checks.'],
            'retention.tool_executions_days' => ['value' => 90, 'description' => 'Days to keep tool execution records.'],
            'retention.task_logs_days' => ['value' => 90, 'description' => 'Days to keep task logs.'],
            'retention.usage_records_months' => ['value' => 24, 'description' => 'Months to keep usage records.'],
            'retention.read_notifications_days' => ['value' => 30, 'description' => 'Days to keep notifications after they are read.'],
            'voice.listening_window_seconds' => ['value' => 60, 'description' => 'Seconds the voice listening window stays open after the wake word.'],
            'voice.session_token_ceiling' => ['value' => 200000, 'description' => 'Maximum tokens reported by a single Realtime voice session.'],
            'voice.realtime_voice' => ['value' => 'marin', 'description' => 'Voice used by the OpenAI Realtime session.'],
            'whatsapp.kill_switch' => ['value' => 0, 'description' => 'Global WhatsApp kill switch (1 blocks every WhatsApp write and delivery).'],
            'whatsapp.max_sends_per_minute' => ['value' => 6, 'description' => 'Maximum WhatsApp sends per session per minute.'],
            'whatsapp.max_sends_per_recipient_per_hour' => ['value' => 10, 'description' => 'Maximum WhatsApp sends to the same recipient per hour.'],
            'whatsapp.daily_send_cap' => ['value' => 200, 'description' => 'Maximum WhatsApp sends per session per day (America/Sao_Paulo).'],
            'whatsapp.min_send_interval_seconds' => ['value' => 8, 'description' => 'Minimum seconds between two WhatsApp deliveries of a session.'],
            'whatsapp.jitter_min_seconds' => ['value' => 2, 'description' => 'Minimum random jitter added to the WhatsApp delivery interval.'],
            'whatsapp.jitter_max_seconds' => ['value' => 7, 'description' => 'Maximum random jitter added to the WhatsApp delivery interval.'],
            'whatsapp.typing_min_ms' => ['value' => 1500, 'description' => 'Minimum duration of the WhatsApp typing presence (ms).'],
            'whatsapp.typing_max_ms' => ['value' => 6000, 'description' => 'Maximum duration of the WhatsApp typing presence (ms).'],
            'whatsapp.max_non_contact_recipients' => ['value' => 5, 'description' => 'Distinct non-contact WhatsApp recipients allowed per window.'],
            'whatsapp.non_contact_window_minutes' => ['value' => 60, 'description' => 'Window (minutes) of the WhatsApp non-contact recipient limit.'],
            'retention.whatsapp_messages_days' => ['value' => 30, 'description' => 'Days to keep stored WhatsApp messages.'],
        ];
    }

    /**
     * Insert missing settings only, preserving values already changed by administrators.
     */
    public function run(): void
    {
        $now = now();

        $rows = collect(static::defaults())->map(fn (array $setting, string $key): array => [
            'key' => $key,
            'value' => json_encode($setting['value']),
            'description' => $setting['description'],
            'created_at' => $now,
            'updated_at' => $now,
        ])->values()->all();

        DB::table('settings')->insertOrIgnore($rows);
    }
}
