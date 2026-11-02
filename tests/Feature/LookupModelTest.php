<?php

namespace Tests\Feature;

use App\Models\AiProviderDriver;
use App\Models\ExecutionStatus;
use App\Models\LookupModel;
use App\Models\TaskStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsLookups;
use Tests\TestCase;

class LookupModelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsLookups;

    public function test_id_for_slug_resolves_and_caches(): void
    {
        $expectedId = (int) DB::table('task_statuses')->where('slug', TaskStatus::WAITING_APPROVAL)->value('id');

        DB::enableQueryLog();

        $this->assertSame($expectedId, TaskStatus::idFor(TaskStatus::WAITING_APPROVAL));
        $this->assertCount(1, DB::getQueryLog());

        $this->assertSame($expectedId, TaskStatus::idFor(TaskStatus::WAITING_APPROVAL));
        $this->assertCount(1, DB::getQueryLog(), 'Second resolution must be served from the in-memory cache.');

        DB::disableQueryLog();
    }

    public function test_cache_is_isolated_per_lookup_model(): void
    {
        $this->assertSame(
            (int) DB::table('task_statuses')->where('slug', 'pending')->value('id'),
            TaskStatus::idFor(TaskStatus::PENDING),
        );

        $this->assertSame(
            (int) DB::table('execution_statuses')->where('slug', 'pending')->value('id'),
            ExecutionStatus::idFor(ExecutionStatus::PENDING),
        );
    }

    public function test_unknown_slug_throws(): void
    {
        $this->expectException(ModelNotFoundException::class);

        TaskStatus::idFor('does_not_exist');
    }

    public function test_find_by_slug_and_active_scope(): void
    {
        $this->assertSame('Waiting Approval', TaskStatus::findBySlug(TaskStatus::WAITING_APPROVAL)?->name);
        $this->assertNull(TaskStatus::findBySlug('nope'));

        TaskStatus::query()->where('slug', TaskStatus::CANCELLED)->update(['is_active' => false]);

        $this->assertSame(6, TaskStatus::active()->count());
        $this->assertSame(13, AiProviderDriver::active()->count());
    }

    public function test_flush_cache_forces_a_new_lookup(): void
    {
        TaskStatus::idFor(TaskStatus::RUNNING);

        LookupModel::flushCache();

        DB::enableQueryLog();
        TaskStatus::idFor(TaskStatus::RUNNING);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }
}
