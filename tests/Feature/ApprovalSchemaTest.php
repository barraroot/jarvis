<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApprovalSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $userId;

    private int $toolId;

    private int $approvalId;

    private int $executionStatusId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = User::factory()->create()->id;

        $toolTypeId = $this->insertLookup('tool_types', 'rest');
        $approvalStatusId = $this->insertLookup('approval_statuses', 'approved');
        $this->executionStatusId = $this->insertLookup('execution_statuses', 'completed');

        $this->toolId = DB::table('tools')->insertGetId([
            'tool_type_id' => $toolTypeId,
            'identifier' => 'mail.send',
            'name' => 'Send mail',
            'description' => 'Sends an e-mail',
            'input_schema' => json_encode(['type' => 'object']),
            'output_schema' => json_encode(['type' => 'object']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->approvalId = DB::table('approvals')->insertGetId([
            'user_id' => $this->userId,
            'tool_id' => $this->toolId,
            'approval_status_id' => $approvalStatusId,
            'input' => json_encode(['to' => 'someone@example.com']),
            'requested_at' => now(),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_approval_can_be_consumed_by_only_one_tool_execution(): void
    {
        $this->insertToolExecution($this->approvalId);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function (): void {
            $this->insertToolExecution($this->approvalId);
        });
    }

    public function test_tool_executions_without_approval_are_unlimited(): void
    {
        $this->insertToolExecution(null);
        $this->insertToolExecution(null);

        $this->assertSame(2, DB::table('tool_executions')->whereNull('approval_id')->count());
    }

    public function test_approval_execution_link_is_cleared_when_approval_is_deleted(): void
    {
        $executionId = $this->insertToolExecution($this->approvalId);

        DB::table('approvals')->where('id', $this->approvalId)->delete();

        $this->assertNull(DB::table('tool_executions')->where('id', $executionId)->value('approval_id'));
    }

    private function insertToolExecution(?int $approvalId): int
    {
        return DB::table('tool_executions')->insertGetId([
            'tool_id' => $this->toolId,
            'user_id' => $this->userId,
            'execution_status_id' => $this->executionStatusId,
            'approval_id' => $approvalId,
            'input' => json_encode(['to' => 'someone@example.com']),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLookup(string $table, string $slug): int
    {
        return DB::table($table)->insertGetId([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
