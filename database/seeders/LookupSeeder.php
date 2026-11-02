<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class LookupSeeder extends Seeder
{
    /**
     * Seed every lookup table in dependency-free order.
     */
    public function run(): void
    {
        $this->call([
            MessageRoleSeeder::class,
            MessageSourceSeeder::class,
            ResultTypeSeeder::class,
            ExecutionStatusSeeder::class,
            TaskStatusSeeder::class,
            ApprovalStatusSeeder::class,
            MemoryStatusSeeder::class,
            MemoryCategorySeeder::class,
            ToolTypeSeeder::class,
            ConnectorStatusSeeder::class,
            AiProviderDriverSeeder::class,
            AiModelCapabilitySeeder::class,
            NotificationTypeSeeder::class,
            AuditActionSeeder::class,
            LogLevelSeeder::class,
        ]);
    }
}
