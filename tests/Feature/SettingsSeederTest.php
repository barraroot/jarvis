<?php

namespace Tests\Feature;

use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_inserts_all_default_keys(): void
    {
        $this->seed(SettingsSeeder::class);

        $this->assertSame(18, DB::table('settings')->count());

        foreach (SettingsSeeder::defaults() as $key => $setting) {
            $stored = DB::table('settings')->where('key', $key)->value('value');

            $this->assertNotNull($stored, "Setting [{$key}] was not seeded.");
            $this->assertEquals($setting['value'], json_decode($stored), "Setting [{$key}] has an unexpected value.");
        }

        $this->assertSame(0.75, json_decode(DB::table('settings')->where('key', 'memory.similarity_threshold')->value('value')));
    }

    public function test_seeder_inserts_voice_defaults(): void
    {
        $this->seed(SettingsSeeder::class);

        $this->assertSame(60, json_decode(DB::table('settings')->where('key', 'voice.listening_window_seconds')->value('value')));
        $this->assertSame(200000, json_decode(DB::table('settings')->where('key', 'voice.session_token_ceiling')->value('value')));
        $this->assertSame('marin', json_decode(DB::table('settings')->where('key', 'voice.realtime_voice')->value('value')));
    }

    public function test_seeder_does_not_overwrite_changed_values(): void
    {
        $this->seed(SettingsSeeder::class);

        DB::table('settings')->where('key', 'memory.top_n')->update(['value' => json_encode(10)]);
        DB::table('settings')->where('key', 'brain.approval_ttl_hours')->delete();

        $this->seed(SettingsSeeder::class);

        $this->assertSame(10, json_decode(DB::table('settings')->where('key', 'memory.top_n')->value('value')));
        $this->assertSame(24, json_decode(DB::table('settings')->where('key', 'brain.approval_ttl_hours')->value('value')));
        $this->assertSame(18, DB::table('settings')->count());
    }
}
