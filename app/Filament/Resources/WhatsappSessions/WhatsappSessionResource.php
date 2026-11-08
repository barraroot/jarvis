<?php

namespace App\Filament\Resources\WhatsappSessions;

use App\Filament\NavigationGroup;
use App\Filament\Resources\WhatsappSessions\Pages\ListWhatsappSessions;
use App\Filament\Resources\WhatsappSessions\Tables\WhatsappSessionsTable;
use App\Models\WhatsappOutboundStatus;
use App\Models\WhatsappSession;
use App\Services\WhatsappSendGuard;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * WhatsApp sessions of every operator (RF-40). Strictly read-only apart from
 * suspend/resume (RF-41): secrets (`token`, `hmac_key`, `webhook_key`) are
 * never selected and messages are never listed.
 */
class WhatsappSessionResource extends Resource
{
    protected static ?string $model = WhatsappSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Ai;

    protected static ?int $navigationSort = 60;

    protected static ?string $modelLabel = 'sessão WhatsApp';

    protected static ?string $pluralModelLabel = 'sessões WhatsApp';

    /**
     * Columns safe to load in the panel; secrets stay in the database.
     *
     * @var list<string>
     */
    public const array SAFE_COLUMNS = [
        'id',
        'user_id',
        'connector_status_id',
        'suspended_at',
        'last_error',
        'last_checked_at',
        'created_at',
    ];

    public static function table(Table $table): Table
    {
        return WhatsappSessionsTable::configure($table);
    }

    /**
     * Owner, status and today's sends (RF-22 units: recipient rows of
     * `sent`/`queued` outbound messages since 00:00 São Paulo) in one query.
     *
     * @return Builder<WhatsappSession>
     */
    public static function getEloquentQuery(): Builder
    {
        $countedStatusIds = [
            WhatsappOutboundStatus::idFor(WhatsappOutboundStatus::SENT),
            WhatsappOutboundStatus::idFor(WhatsappOutboundStatus::QUEUED),
        ];

        return parent::getEloquentQuery()
            ->select(array_map(fn (string $column): string => "whatsapp_sessions.{$column}", self::SAFE_COLUMNS))
            ->with(['user:id,name', 'status'])
            ->withCount(['outboundRecipients as sends_today_count' => fn (Builder $query) => $query
                ->where('whatsapp_outbound_recipients.created_at', '>=', WhatsappSendGuard::startOfToday())
                ->whereHas('outboundMessage', fn (Builder $message) => $message->whereIn('whatsapp_outbound_status_id', $countedStatusIds)),
            ]);
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

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsappSessions::route('/'),
        ];
    }
}
