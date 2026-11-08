<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            LookupSeeder::class,
            SettingsSeeder::class,
            AdminUserSeeder::class,
            RememberMemoryToolSeeder::class,
            AgentSeeder::class,
            VisualizationToolSeeder::class,
            WhatsappToolSeeder::class,
        ]);
    }
}
