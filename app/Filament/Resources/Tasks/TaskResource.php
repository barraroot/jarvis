<?php

namespace App\Filament\Resources\Tasks;

use App\Filament\NavigationGroup;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\Pages\ViewTask;
use App\Filament\Resources\Tasks\RelationManagers\LogsRelationManager;
use App\Filament\Resources\Tasks\RelationManagers\StepsRelationManager;
use App\Filament\Resources\Tasks\Schemas\TaskInfolist;
use App\Filament\Resources\Tasks\Tables\TasksTable;
use App\Models\AuditAction;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Support\AuditLogger;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Background tasks of every operator (US-12.1): steps, logs and result are
 * read-only; the only write is the admin cancel, audited as `task_cancelled`.
 */
class TaskResource extends Resource
{
    protected static ?string $model = Task::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Operation;

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'tarefa';

    protected static ?string $pluralModelLabel = 'tarefas';

    protected static ?string $recordTitleAttribute = 'title';

    public static function infolist(Schema $schema): Schema
    {
        return TaskInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TasksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StepsRelationManager::class,
            LogsRelationManager::class,
        ];
    }

    /**
     * The policy is owner-only (API); administrators inspect any task here.
     */
    public static function canView(Model $record): bool
    {
        return true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Admin cancel of any cancellable task: same cooperative cancellation as
     * the owner's (TaskService), plus a `task_cancelled` audit entry.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Cancelar')
            ->icon(Heroicon::OutlinedStopCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Task $record): bool => $record->canBeCancelled())
            ->action(function (Task $record): void {
                /** @var User $admin */
                $admin = auth()->user();

                app(TaskService::class)->cancel($record, $admin);

                app(AuditLogger::class)->log(AuditAction::TASK_CANCELLED, $admin, $record, [
                    'title' => $record->title,
                    'owner_user_id' => $record->user_id,
                    'by_admin' => true,
                ]);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTasks::route('/'),
            'view' => ViewTask::route('/{record}'),
        ];
    }
}
