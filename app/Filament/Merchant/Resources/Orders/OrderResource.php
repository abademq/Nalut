<?php

namespace App\Filament\Merchant\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Merchant\Resources\Orders\Pages\ListOrders;
use App\Filament\Merchant\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\LocalDay;
use App\Support\Merchant;
use App\Support\OrderMoney;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** طلبات المتجر — نفس اللي يشوفه في التطبيق، وبنفس حدود «ما يظهر للمتجر» */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getModelLabel(): string
    {
        return 'طلب';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الطلبات';
    }

    public static function canAccess(): bool
    {
        return Merchant::ordersEnabled();
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', OrderStatus::Pending->value)->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** طلبات متجره بس — والبطاقة اللي ما تأكد دفعها ما تبانش (زي التطبيق) */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('store_id', Merchant::storeId())
            ->where(fn ($q) => $q->where('payment_method', '!=', 'card')->orWhere('is_paid', true));
    }

    public static function shows(string $what): bool
    {
        return OrderMoney::can('store', $what);
    }

    public static function statusColor(OrderStatus $s): string
    {
        return match ($s) {
            OrderStatus::Pending => 'danger',
            OrderStatus::Accepted, OrderStatus::Preparing => 'warning',
            OrderStatus::Ready, OrderStatus::Assigned => 'info',
            OrderStatus::PickedUp, OrderStatus::OnTheWay => 'primary',
            OrderStatus::Delivered => 'success',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('code')->label('رقم الطلب')->weight('bold')->searchable(),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => static::statusColor($state)),
                TextColumn::make('created_at')->label('الوقت')->since()
                    ->tooltip(fn (Order $r) => $r->created_at?->timezone(LocalDay::timezone())->format('d/m H:i')),
                TextColumn::make('items_summary')->label('الأصناف')->wrap()
                    ->state(fn (Order $r) => $r->items->map(fn ($i) => "{$i->quantity}× {$i->name}")->implode('، ')),
                TextColumn::make('customer.name')->label('الزبون')->placeholder('—')
                    ->visible(static::shows('customer_name')),
                TextColumn::make('subtotal')->label('قيمة الأصناف')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل')
                    ->visible(static::shows('item_prices')),
                TextColumn::make('store_earning')->label('صافي المتجر')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل')
                    ->visible(static::shows('store_net'))
                    ->toggleable(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['items', 'customer']))
            ->recordActions([ViewAction::make()->label('فتح')])
            ->recordUrl(fn (Order $r) => static::getUrl('view', ['record' => $r]))
            ->emptyStateHeading('ما فيش طلبات هني');
    }

    /** الأصناف: الزيادة بالأخضر والإزالة «بدون» بالأحمر — نفس تطبيق المتجر */
    public static function itemsHtml(Order $o): string
    {
        $prices = static::shows('item_prices');

        return $o->items->map(function (OrderItem $i) use ($prices) {
            $name = e("{$i->quantity} × {$i->name}");
            $line = $i->is_unavailable ? "<s style=\"color:#9ca3af\">{$name}</s> <b style=\"color:#b91c1c\">مش متوفر</b>" : "<b>{$name}</b>";
            if ($prices) {
                $line .= ' — '.number_format($i->line_total, 2).' د.ل';
            }
            if (($added = $i->addedText()) !== '') {
                $line .= '<br><span style="color:#15803d;font-weight:600">+ '.e($added).'</span>';
            }
            if (($removed = $i->removedText()) !== '') {
                $line .= '<br><span style="color:#b91c1c;font-weight:700">✕ بدون: '.e($removed).'</span>';
            }
            if (filled($i->note)) {
                $line .= '<br><span style="color:#b45309">ملاحظة: '.e($i->note).'</span>';
            }

            return '<div style="padding:6px 0;border-bottom:1px solid rgba(127,127,127,.15)">'.$line.'</div>';
        })->implode('');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('الطلب')->columnSpanFull()->columns(3)->schema([
                TextEntry::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => static::statusColor($state)),
                TextEntry::make('created_at')->label('وقت الطلب')->dateTime('d/m/Y H:i', LocalDay::timezone()),
                TextEntry::make('eta')->label('جاهز متوقع')->placeholder('—')
                    ->state(fn (Order $r) => $r->readyEta()?->timezone(LocalDay::timezone())->format('H:i')),
                TextEntry::make('awaiting')->label('')->columnSpanFull()->color('warning')->weight('bold')
                    ->state('الطلب يستنى رد الزبون على الأصناف الناقصة — تقدر ترفضه بس لين يرد.')
                    ->visible(fn (Order $r) => $r->awaiting_customer_at !== null && ! $r->status->isFinal()),
                TextEntry::make('cancel_reason')->label('سبب الإلغاء')->columnSpanFull()->color('danger')
                    ->visible(fn (Order $r) => filled($r->cancel_reason)),
            ]),

            Section::make('الأصناف')->columnSpanFull()->schema([
                TextEntry::make('items_html')->hiddenLabel()->html()
                    ->state(fn (Order $r) => static::itemsHtml($r)),
                TextEntry::make('notes')->label('ملاحظة الزبون على الطلب')->color('warning')
                    ->visible(fn (Order $r) => filled($r->notes)),
            ]),

            Section::make('الزبون')->columnSpan(1)
                ->visible(static::shows('customer_name') || static::shows('customer_phone') || static::shows('customer_address'))
                ->schema([
                    TextEntry::make('customer.name')->label('الاسم')->placeholder('—')->visible(static::shows('customer_name')),
                    TextEntry::make('customer_phone')->label('الهاتف')->placeholder('—')->visible(static::shows('customer_phone'))
                        ->url(fn (Order $r) => $r->customer_phone ? 'tel:'.$r->customer_phone : null),
                    TextEntry::make('address_details')->label('العنوان')->placeholder('—')->visible(static::shows('customer_address'))
                        ->state(fn (Order $r) => trim($r->address_details.($r->address_landmark ? ' — '.$r->address_landmark : ''))),
                ]),

            Section::make('السائق')->columnSpan(1)
                ->visible(static::shows('driver'))
                ->schema([
                    TextEntry::make('driver.name')->label('الاسم')->placeholder('ما تعيّنش سائق لسه'),
                    TextEntry::make('driver.phone')->label('الهاتف')->placeholder('—')
                        ->url(fn (Order $r) => $r->driver?->phone ? 'tel:'.$r->driver->phone : null),
                ]),

            Section::make('الفلوس')->columnSpanFull()->schema([
                TextEntry::make('money')->hiddenLabel()->html()
                    ->state(fn (Order $r) => static::moneyHtml($r)),
            ]),
        ]);
    }

    public static function moneyHtml(Order $o): string
    {
        $lines = OrderMoney::lines($o, 'store');
        if ($lines === []) {
            return '<span style="color:#9ca3af">—</span>';
        }

        $html = '';
        foreach (OrderMoney::GROUPS as $g => $title) {
            $rows = array_filter($lines, fn ($l) => $l['group'] === $g);
            if (! $rows) {
                continue;
            }
            $html .= '<div style="font-weight:700;margin:8px 0 4px">'.e($title).'</div>';
            foreach ($rows as $l) {
                $style = match ($l['style']) {
                    'total' => 'font-weight:800',
                    'highlight' => 'font-weight:800;color:#b45309',
                    'minus' => 'color:#b91c1c',
                    'muted' => 'color:#6b7280',
                    default => '',
                };
                $hint = isset($l['hint']) ? ' <small style="color:#6b7280">('.e($l['hint']).')</small>' : '';
                $html .= '<div style="display:flex;justify-content:space-between;gap:12px;padding:2px 0;'.$style.'"><span>'.e($l['label']).$hint.'</span><span dir="ltr">'.number_format($l['amount'], 2).' د.ل</span></div>';
            }
        }

        return $html;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
