<?php

namespace Tests\Feature;

use App\Exceptions\UsageLimitExceededException;
use App\Models\Notification;
use App\Models\NotificationType;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\CostLimitGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class CostLimitGuardTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();
    }

    public function test_user_over_monthly_limit_is_blocked_and_notified_once(): void
    {
        $user = User::factory()->create(['monthly_cost_limit' => 10]);

        UsageRecord::factory()->create([
            'user_id' => $user->id,
            'estimated_cost' => 15,
            'created_at' => now()->startOfMonth()->addHour(),
        ]);

        $guard = app(CostLimitGuard::class);

        try {
            $guard->assertWithinLimit($user);

            $this->fail('Expected UsageLimitExceededException was not thrown.');
        } catch (UsageLimitExceededException) {
            $this->assertTrue(true);
        }

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'notification_type_id' => NotificationType::idFor(NotificationType::USAGE_LIMIT_REACHED),
            'title' => 'Limite mensal de uso de IA atingido',
        ]);

        // A second breach in the same month must not create another notification.
        try {
            $guard->assertWithinLimit($user);
        } catch (UsageLimitExceededException) {
            $this->assertTrue(true);
        }

        $this->assertSame(1, Notification::query()->where('user_id', $user->id)->count());
    }

    public function test_user_without_limit_is_never_blocked(): void
    {
        $user = User::factory()->create(['monthly_cost_limit' => null]);

        UsageRecord::factory()->create([
            'user_id' => $user->id,
            'estimated_cost' => 9999,
            'created_at' => now(),
        ]);

        app(CostLimitGuard::class)->assertWithinLimit($user);

        $this->assertSame(0, Notification::query()->where('user_id', $user->id)->count());
    }

    public function test_spending_exactly_the_limit_is_allowed(): void
    {
        $user = User::factory()->create(['monthly_cost_limit' => 10]);

        UsageRecord::factory()->create([
            'user_id' => $user->id,
            'estimated_cost' => 10,
            'created_at' => now(),
        ]);

        app(CostLimitGuard::class)->assertWithinLimit($user);

        $this->assertSame(0, Notification::query()->where('user_id', $user->id)->count());
    }

    public function test_spending_from_previous_months_does_not_count(): void
    {
        $user = User::factory()->create(['monthly_cost_limit' => 10]);

        UsageRecord::factory()->create([
            'user_id' => $user->id,
            'estimated_cost' => 900,
            'created_at' => now()->subMonth(),
        ]);

        app(CostLimitGuard::class)->assertWithinLimit($user);

        $this->assertSame(0, Notification::query()->where('user_id', $user->id)->count());
    }
}
