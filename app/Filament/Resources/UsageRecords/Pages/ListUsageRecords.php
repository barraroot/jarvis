<?php

namespace App\Filament\Resources\UsageRecords\Pages;

use App\Filament\Resources\UsageRecords\UsageRecordResource;
use App\Models\UsageRecord;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListUsageRecords extends ListRecords
{
    protected static string $resource = UsageRecordResource::class;

    /**
     * CSV header row of the export, in column order.
     *
     * @var list<string>
     */
    public const array CSV_HEADER = ['id', 'created_at', 'user', 'provider', 'model', 'status', 'input_tokens', 'output_tokens', 'estimated_cost', 'latency_ms'];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Exportar CSV')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    /**
     * Streams the currently filtered and sorted records as CSV, in chunks.
     */
    public function exportCsv(): StreamedResponse
    {
        $query = $this->getFilteredSortedTableQuery()
            ->with(['user:id,name', 'provider:id,name', 'model:id,name', 'status:id,slug']);

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, self::CSV_HEADER, escape: '');

            foreach ($query->lazy(500) as $record) {
                /** @var UsageRecord $record */
                fputcsv($output, [
                    $record->id,
                    $record->created_at?->toIso8601String(),
                    $record->user?->name,
                    $record->provider?->name,
                    $record->model?->name,
                    $record->status?->slug,
                    $record->input_tokens,
                    $record->output_tokens,
                    $record->estimated_cost,
                    $record->latency_ms,
                ], escape: '');
            }

            fclose($output);
        }, 'usage-records-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
