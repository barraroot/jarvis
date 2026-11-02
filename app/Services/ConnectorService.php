<?php

namespace App\Services;

use App\Jobs\SyncMcpServerTools;
use App\Models\AuditAction;
use App\Models\ConnectorStatus;
use App\Models\McpServer;
use App\Models\Tool;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connector lifecycle shared by the operator API and the admin panel
 * (US-9.1/9.2): create, edit, remove and resync, always audited and never
 * persisting the credential in the audit trail. Saving a new endpoint or
 * credential dispatches tool discovery (US-9.3).
 */
class ConnectorService
{
    /**
     * Attributes whose change requires a new discovery.
     *
     * @var list<string>
     */
    private const array SYNC_TRIGGERS = ['url', 'credential', 'is_active'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, url: string, credential?: string|null, is_active?: bool}  $attributes
     */
    public function create(array $attributes, ?User $owner, ?User $actor): McpServer
    {
        $server = McpServer::query()->create([
            ...$attributes,
            'user_id' => $owner?->id,
            'connector_status_id' => ConnectorStatus::idFor(ConnectorStatus::PENDING),
            'is_active' => $attributes['is_active'] ?? true,
        ]);

        $this->audit->log(AuditAction::CONNECTOR_CREATED, $actor, $server, [
            'name' => $server->name,
            'url' => $server->url,
            'scope' => $server->isGlobal() ? 'global' : 'personal',
        ]);

        if ($server->is_active) {
            $this->resync($server);
        }

        return $server;
    }

    /**
     * A blank credential keeps the stored one (secrets are never re-displayed).
     *
     * @param  array{name?: string, url?: string, credential?: string|null, is_active?: bool}  $attributes
     */
    public function update(McpServer $server, array $attributes, ?User $actor): McpServer
    {
        if (array_key_exists('credential', $attributes) && blank($attributes['credential'])) {
            unset($attributes['credential']);
        }

        $server->fill($attributes);
        $dirty = array_keys($server->getDirty());
        $server->save();

        if ($dirty === []) {
            return $server;
        }

        $this->audit->log(AuditAction::CONNECTOR_UPDATED, $actor, $server, [
            'changed' => array_values(array_map(fn (string $key): string => $key === 'credential' ? 'secret' : $key, $dirty)),
        ]);

        if ($server->is_active && array_intersect($dirty, self::SYNC_TRIGGERS) !== []) {
            $this->resync($server);
        }

        return $server;
    }

    /**
     * Soft-delete the connector; its tools are deactivated and its name freed
     * for reuse (the `(user_id, name)` index also covers trashed rows).
     */
    public function delete(McpServer $server, ?User $actor): void
    {
        DB::transaction(function () use ($server, $actor): void {
            $definitionIds = $server->toolDefinitions()->pluck('id');

            $server->toolDefinitions()->update(['is_available' => false]);
            Tool::query()->whereIn('mcp_tool_definition_id', $definitionIds)->update(['is_active' => false]);

            $this->audit->log(AuditAction::CONNECTOR_DELETED, $actor, $server, ['name' => $server->name]);

            $server->forceFill([
                'name' => Str::limit($server->name, 200, '').' #'.$server->id.' (removido)',
            ])->save();
            $server->delete();
        });
    }

    public function resync(McpServer $server): void
    {
        SyncMcpServerTools::dispatch($server);
    }
}
