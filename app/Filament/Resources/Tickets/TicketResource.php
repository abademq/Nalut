<?php

namespace App\Filament\Resources\Tickets;

use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Ticket;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** تذاكر الدعم من الزبائن والسائقين والمتاجر */
class TicketResource extends Resource
{
    use GuardedByPermission;

    public const PERM_VIEW = 'support.manage';

    public const PERM_MANAGE = 'support.manage';

    protected static ?string $model = Ticket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'الطلبات';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getModelLabel(): string
    {
        return 'تذكرة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'تذاكر الدعم';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** عدد التذاكر اللي تستنى رد */
    public static function getNavigationBadge(): ?string
    {
        $n = Ticket::where('admin_unread', true)->where('status', '!=', 'closed')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function statusColor(string $s): string
    {
        return match ($s) {
            'open' => 'danger',
            'answered' => 'success',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->poll('15s')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'order', 'lastMessage', 'assignee']))
            ->columns([
                IconColumn::make('admin_unread')->label('')->boolean()
                    ->trueIcon('heroicon-s-chat-bubble-left-ellipsis')->falseIcon('heroicon-o-check')
                    ->trueColor('danger')->falseColor('gray')
                    ->tooltip(fn (Ticket $r) => $r->admin_unread ? 'فيها رسالة جديدة ما تقراتش' : null),
                TextColumn::make('code')->label('رقم')->weight('bold')->searchable(),
                TextColumn::make('app')->label('من')->badge()
                    ->formatStateUsing(fn ($state) => Ticket::APPS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'driver' => 'info', 'store' => 'warning', default => 'gray'
                    }),
                TextColumn::make('user.name')->label('الاسم')->searchable()
                    ->description(fn (Ticket $r) => $r->user?->phone),
                TextColumn::make('subject')->label('الموضوع')->wrap()->limit(60)->searchable()
                    ->description(fn (Ticket $r) => $r->categoryLabel().($r->order ? ' · طلب '.$r->order->code : '')),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => Ticket::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => static::statusColor($state)),
                TextColumn::make('assignee.name')->label('المسؤول')->placeholder('—')->toggleable(),
                TextColumn::make('last_message_at')->label('آخر رسالة')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(Ticket::STATUSES)->default(null),
                SelectFilter::make('app')->label('من')->options(Ticket::APPS),
                Filter::make('waiting')->label('تستنى رد')
                    ->query(fn ($query) => $query->where('status', 'open')),
                Filter::make('mine')->label('المسندة لي')
                    ->query(fn ($query) => $query->where('assigned_to', auth()->id())),
            ])
            ->recordActions([ViewAction::make()->label('فتح')])
            ->recordUrl(fn (Ticket $r) => static::getUrl('view', ['record' => $r]))
            ->emptyStateHeading('ما فيش تذاكر');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTickets::route('/'),
            'view' => ViewTicket::route('/{record}'),
        ];
    }
}
