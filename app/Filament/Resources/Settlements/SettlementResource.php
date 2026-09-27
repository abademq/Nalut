<?php

namespace App\Filament\Resources\Settlements;

use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Resources\Settlements\Pages\ViewSettlement;
use App\Models\Settlement;
use App\Services\SettlementService;
use App\Support\Perm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** سجل التسويات — كل تسوية بواصلها */
class SettlementResource extends Resource
{
    use GuardedByPermission;

    public const PERM_VIEW = 'settlements.view';

    public const PERM_MANAGE = 'settlements.manage';

    protected static ?string $model = Settlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'التسويات';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'settlements';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return 'تسوية';
    }

    public static function getPluralModelLabel(): string
    {
        return 'سجل التسويات';
    }

    public static function getNavigationLabel(): string
    {
        return 'سجل التسويات';
    }

    public static function printAction(): Action
    {
        return Action::make('print')
            ->label('طباعة الواصل')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->url(fn (Settlement $record) => route('settlements.print', ['settlement' => $record, 'paper' => '80']))
            ->openUrlInNewTab();
    }

    public static function printA4Action(): Action
    {
        return Action::make('printA4')
            ->label('طباعة A4 (مع الطلبات)')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->url(fn (Settlement $record) => route('settlements.print', ['settlement' => $record, 'paper' => 'a4']))
            ->openUrlInNewTab();
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancelSettlement')
            ->label('إلغاء التسوية')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (Settlement $record) => ! $record->isCancelled() && Perm::can('settlements.manage'))
            ->requiresConfirmation()
            ->modalDescription('يرجع المبلغ للرصيد بحركة عكسية، والطلبات ترجع «ما تسوّتش». التسوية تقعد في السجل بعلامة «ملغية».')
            ->schema([Textarea::make('reason')->label('سبب الإلغاء')->required()->maxLength(300)])
            ->action(function (Settlement $record, array $data) {
                app(SettlementService::class)->cancel($record, $data['reason'], auth()->user());
                Notification::make()->title('تم إلغاء التسوية')->success()->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'store', 'creator']))
            ->columns([
                TextColumn::make('number')->label('الرقم')->searchable()->weight('bold')
                    ->description(fn (Settlement $r) => $r->isCancelled() ? 'ملغية' : null),
                TextColumn::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('party')->label('النوع')->badge()
                    ->formatStateUsing(fn ($state) => Settlement::PARTIES[$state] ?? $state)
                    ->color(fn ($state) => $state === 'store' ? 'info' : 'warning'),
                TextColumn::make('user.name')->label('الحساب')
                    ->state(fn (Settlement $r) => $r->partyName())
                    ->description(fn (Settlement $r) => $r->user?->phone)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('user', fn ($u) => $u->where('name', 'like', "%$search%")->orWhere('phone', 'like', "%$search%"))
                        ->orWhereHas('store', fn ($s) => $s->where('name', 'like', "%$search%"))),
                TextColumn::make('direction')->label('العملية')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'pay' ? 'صرفنا له' : 'استلمنا منه')
                    ->color(fn ($state) => $state === 'pay' ? 'danger' : 'success'),
                TextColumn::make('amount')->label('المبلغ')->numeric(2)->suffix(' د.ل')->weight('bold')->sortable()
                    ->extraAttributes(fn (Settlement $r) => $r->isCancelled() ? ['style' => 'text-decoration:line-through;opacity:.6'] : []),
                TextColumn::make('orders_count')->label('طلبات'),
                TextColumn::make('method')->label('الطريقة')->formatStateUsing(fn ($state) => Settlement::METHODS[$state] ?? $state),
                TextColumn::make('balance_after')->label('الرصيد بعدها')->numeric(2)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')->label('بواسطة')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('party')->label('النوع')->options(Settlement::PARTIES),
                SelectFilter::make('direction')->label('العملية')->options(['pay' => 'صرف', 'receive' => 'استلام']),
                Filter::make('active')->label('بدون الملغية')->default()->query(fn (Builder $query) => $query->whereNull('cancelled_at')),
            ])
            ->recordActions([self::printAction(), self::cancelAction()])
            ->recordUrl(fn (Settlement $r) => ViewSettlement::getUrl(['record' => $r]));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSettlements::route('/'),
            'view' => ViewSettlement::route('/{record}'),
        ];
    }
}
