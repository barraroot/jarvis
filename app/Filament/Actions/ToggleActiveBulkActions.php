<?php

namespace App\Filament\Actions;

use App\Support\AuditLogger;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * "Ativar" / "Desativar" bulk actions for catalog listings with an
 * `is_active` toggle (providers, tools, agents — US-12.1). Each record that
 * actually changes is audited with the resource's `*_updated` action.
 */
class ToggleActiveBulkActions
{
    /**
     * @param  string  $auditAction  slug logged for every changed record
     * @param  (Closure(Model): ?string)|null  $deactivationBlocker  returns a reason when a record must stay active
     * @param  (Closure(): void)|null  $after  runs once after any record changed (e.g. resync derived data)
     * @return array{0: BulkAction, 1: BulkAction}
     */
    public static function make(string $auditAction, ?Closure $deactivationBlocker = null, ?Closure $after = null): array
    {
        return [
            self::action('activate', true, $auditAction, null, $after),
            self::action('deactivate', false, $auditAction, $deactivationBlocker, $after),
        ];
    }

    /**
     * @param  (Closure(Model): ?string)|null  $blocker
     * @param  (Closure(): void)|null  $after
     */
    private static function action(string $name, bool $active, string $auditAction, ?Closure $blocker, ?Closure $after): BulkAction
    {
        return BulkAction::make($name)
            ->label($active ? 'Ativar' : 'Desativar')
            ->icon($active ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedNoSymbol)
            ->color($active ? 'success' : 'danger')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($active, $auditAction, $blocker, $after): void {
                $changed = 0;
                $skipped = [];

                foreach ($records as $record) {
                    if ((bool) $record->getAttribute('is_active') === $active) {
                        continue;
                    }

                    if ($blocker !== null && ($reason = $blocker($record)) !== null) {
                        $skipped[] = $reason;

                        continue;
                    }

                    $record->update(['is_active' => $active]);

                    app(AuditLogger::class)->log($auditAction, auth()->user(), $record, [
                        'name' => $record->getAttribute('name'),
                        'changed' => ['is_active'],
                        'changes' => ['is_active' => $active],
                        'bulk' => true,
                    ]);

                    $changed++;
                }

                if ($changed > 0 && $after !== null) {
                    $after();
                }

                $notification = Notification::make()
                    ->title(sprintf('%d registro(s) %s', $changed, $active ? 'ativado(s)' : 'desativado(s)'));

                if ($skipped !== []) {
                    $notification->warning()->body(implode(' ', array_unique($skipped)));
                } else {
                    $notification->success();
                }

                $notification->send();
            });
    }
}
