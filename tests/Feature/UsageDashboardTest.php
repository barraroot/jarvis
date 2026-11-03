<?php

namespace Tests\Feature;

use App\Filament\Resources\UsageRecords\Pages\ListUsageRecords;
use App\Filament\Resources\UsageRecords\UsageRecordResource;
use App\Filament\Widgets\UsageByModelTable;
use App\Filament\Widgets\UsageByUserTable;
use App\Filament\Widgets\UsageStatsOverview;
use App\Models\AiModel;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\UsageReport;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class UsageDashboardTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSeedsLookups();

        Filament::setCurrentPanel('admin');

        $this->travelTo(CarbonImmutable::parse('2026-11-15 12:00:00'));

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    public function test_totals_group_by_user_model_and_period(): void
    {
        $alice = User::factory()->create(['name' => 'Alice', 'monthly_cost_limit' => 10]);
        $bob = User::factory()->create(['name' => 'Bob', 'monthly_cost_limit' => null]);

        $gpt = AiModel::factory()->create(['name' => 'gpt-test']);
        $claude = AiModel::factory()->create(['name' => 'claude-test']);

        $record = fn (User $user, AiModel $model, float $cost, int $in, int $out, int $latency, string $at): UsageRecord => UsageRecord::factory()
            ->for($user)
            ->for($model, 'model')
            ->create([
                'ai_provider_id' => $model->ai_provider_id,
                'estimated_cost' => $cost,
                'input_tokens' => $in,
                'output_tokens' => $out,
                'latency_ms' => $latency,
                'created_at' => $at,
            ]);

        // Today.
        $record($alice, $gpt, 2.0, 100, 50, 200, '2026-11-15 09:00:00');
        $record($bob, $claude, 1.0, 10, 5, 400, '2026-11-15 10:00:00');
        // Earlier this month.
        $record($alice, $claude, 3.0, 300, 100, 600, '2026-11-03 10:00:00');
        // Previous month: outside every default window.
        $record($bob, $gpt, 50.0, 1000, 1000, 1000, '2026-10-20 10:00:00');

        $report = app(UsageReport::class);

        $today = $report->today();
        $this->assertEqualsWithDelta(3.0, $today['cost'], 0.0001);
        $this->assertSame(165, $today['tokens']);
        $this->assertSame(2, $today['calls']);
        $this->assertEqualsWithDelta(300.0, $today['avg_latency_ms'], 0.01);
        $this->assertSame(390, $today['p95_latency_ms']);

        $month = $report->currentMonth();
        $this->assertEqualsWithDelta(6.0, $month['cost'], 0.0001);
        $this->assertSame(565, $month['tokens']);
        $this->assertSame(3, $month['calls']);

        // Default period = current month; explicit period covering October.
        [$from, $until] = UsageReport::period(null);
        $this->assertSame('2026-11-01', $from->toDateString());

        [$octFrom, $octUntil] = UsageReport::period(['from' => '2026-10-01', 'until' => '2026-10-31']);
        $october = $report->totals($octFrom, $octUntil);
        $this->assertEqualsWithDelta(50.0, $october['cost'], 0.0001);
        $this->assertSame(1, $october['calls']);

        // By user: most expensive first, monthly limit share computed on the month.
        $users = $report->userRankingQuery($from, $until)->get();
        $this->assertSame(['Alice', 'Bob'], $users->pluck('name')->all());
        $this->assertEqualsWithDelta(5.0, (float) $users[0]->period_cost, 0.0001);
        $this->assertSame(550, (int) $users[0]->period_input_tokens + (int) $users[0]->period_output_tokens);
        $this->assertSame(2, $users[0]->period_calls);
        $this->assertSame(50.0, UsageReport::limitPercent($users[0], (float) $users[0]->month_cost));
        $this->assertNull(UsageReport::limitPercent($users[1], (float) $users[1]->month_cost));

        // October ranking puts Bob alone.
        $this->assertSame(['Bob'], $report->userRankingQuery($octFrom, $octUntil)->get()->pluck('name')->all());

        // By model.
        $models = $report->modelBreakdownQuery($from, $until)->get();
        $this->assertSame(['claude-test', 'gpt-test'], $models->pluck('name')->all());
        $this->assertEqualsWithDelta(4.0, (float) $models[0]->period_cost, 0.0001);
        $this->assertSame(2, $models[0]->period_calls);
        $this->assertEqualsWithDelta(500.0, (float) $models[0]->period_avg_latency_ms, 0.01);

        // Dashboard renders and widgets respect the page filters.
        $this->get('/admin/usage')->assertOk()->assertSee('Consumo e custos');

        Livewire::test(UsageStatsOverview::class, ['pageFilters' => ['from' => '2026-11-01', 'until' => '2026-11-15']])
            ->assertSee('R$ 3,00')
            ->assertSee('R$ 6,00');

        Livewire::test(UsageByUserTable::class, ['pageFilters' => ['from' => '2026-11-01', 'until' => '2026-11-15']])
            ->assertCanSeeTableRecords([$alice, $bob], inOrder: true)
            ->assertSee('50,0%');

        Livewire::test(UsageByUserTable::class, ['pageFilters' => ['from' => '2026-10-01', 'until' => '2026-10-31']])
            ->assertCanSeeTableRecords([$bob])
            ->assertCanNotSeeTableRecords([$alice]);

        Livewire::test(UsageByModelTable::class, ['pageFilters' => ['from' => '2026-11-01', 'until' => '2026-11-15']])
            ->assertCanSeeTableRecords([$claude, $gpt], inOrder: true);

        // Read-only resource with filters and CSV export of the filtered rows.
        $this->assertFalse(UsageRecordResource::hasPage('create'));
        $this->assertFalse(UsageRecordResource::hasPage('edit'));

        Livewire::test(ListUsageRecords::class)
            ->filterTable('user', $alice->id)
            ->assertCountTableRecords(2)
            ->filterTable('model', $claude->id)
            ->assertCountTableRecords(1);

        Livewire::test(ListUsageRecords::class)
            ->filterTable('period', ['from' => '2026-10-01', 'until' => '2026-10-31'])
            ->assertCountTableRecords(1)
            ->callAction('exportCsv')
            ->assertFileDownloaded();

        $csv = Livewire::test(ListUsageRecords::class)
            ->filterTable('user', $bob->id)
            ->instance()
            ->exportCsv();

        ob_start();
        $csv->sendContent();
        $lines = array_filter(explode("\n", (string) ob_get_clean()));

        $this->assertCount(3, $lines);
        $this->assertStringStartsWith(implode(',', ListUsageRecords::CSV_HEADER), $lines[0]);
        $this->assertStringContainsString('Bob', $lines[1]);
    }
}
