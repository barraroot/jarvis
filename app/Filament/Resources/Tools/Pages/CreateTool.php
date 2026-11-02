<?php

namespace App\Filament\Resources\Tools\Pages;

use App\Filament\Resources\Tools\ToolResource;
use App\Models\AuditAction;
use App\Models\Tool;
use App\Support\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateTool extends CreateRecord
{
    protected static string $resource = ToolResource::class;

    protected function afterCreate(): void
    {
        /** @var Tool $tool */
        $tool = $this->getRecord();

        app(AuditLogger::class)->log(AuditAction::TOOL_CREATED, auth()->user(), $tool, [
            'identifier' => $tool->identifier,
            'name' => $tool->name,
            'tool_type' => $tool->type->slug,
            'is_write' => $tool->is_write,
            'is_sensitive' => $tool->is_sensitive,
            'timeout_seconds' => $tool->timeout_seconds,
        ]);
    }
}
