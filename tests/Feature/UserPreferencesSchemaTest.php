<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserPreferencesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_only_one_preferences_row(): void
    {
        $user = User::factory()->create();

        DB::table('user_preferences')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($user): void {
            DB::table('user_preferences')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        });
    }

    public function test_user_preferences_have_expected_defaults(): void
    {
        $user = User::factory()->create();

        DB::table('user_preferences')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        $preferences = DB::table('user_preferences')->where('user_id', $user->id)->first();

        $this->assertSame('pt-BR', $preferences->locale);
        $this->assertSame('pt-BR', $preferences->speech_locale);
        $this->assertSame(1.0, (float) $preferences->speech_rate);
        $this->assertTrue((bool) $preferences->spoken_responses_enabled);
        $this->assertFalse((bool) $preferences->wake_word_enabled);
        $this->assertSame(1500, (int) $preferences->silence_timeout_ms);
        $this->assertNull($preferences->extra);
    }

    public function test_preferences_are_deleted_with_their_user(): void
    {
        $user = User::factory()->create();

        DB::table('user_preferences')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);

        DB::table('users')->where('id', $user->id)->delete();

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_users_table_has_admin_activity_and_soft_delete_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['is_admin', 'is_active', 'monthly_cost_limit', 'last_login_at', 'deleted_at']));

        $user = User::factory()->create();
        $row = DB::table('users')->where('id', $user->id)->first();

        $this->assertFalse((bool) $row->is_admin);
        $this->assertTrue((bool) $row->is_active);
        $this->assertNull($row->monthly_cost_limit);
    }

    public function test_settings_key_is_unique_and_value_is_json(): void
    {
        DB::table('settings')->insert(['key' => 'brain.max_tool_calls', 'value' => json_encode(20), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(20, json_decode(DB::table('settings')->where('key', 'brain.max_tool_calls')->value('value')));

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function (): void {
            DB::table('settings')->insert(['key' => 'brain.max_tool_calls', 'value' => json_encode(30), 'created_at' => now(), 'updated_at' => now()]);
        });
    }
}
