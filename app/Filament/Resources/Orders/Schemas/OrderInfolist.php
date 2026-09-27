<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('الطلب')
                ->columns(3)
                ->schema([
                    TextEntry::make('code')
                        ->label('رقم الطلب')
                        ->weight('bold')
                        ->copyable(),

                    TextEntry::make('status')
                        ->label('الحالة')
                        ->badge()
                        ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                        ->color(fn (OrderStatus $state) => match ($state) {
                            OrderStatus::Delivered => 'success',
                            OrderStatus::Cancelled, OrderStatus::Failed => 'danger',
                            OrderStatus::Pending => 'warning',
                            default => 'info',
                        }),

                    TextEntry::make('created_at')
                        ->label('وقت الطلب')
                        ->dateTime('H:i — d/m/Y'),
                ]),

            Section::make('الأطراف')
                ->columns(3)
                ->schema([
                    TextEntry::make('customer.name')->label('الزبون'),
                    TextEntry::make('customer_phone')->label('هاتف الزبون')->copyable(),
                    TextEntry::make('store.name')->label('المتجر'),
                    TextEntry::make('driver.name')->label('السائق')->placeholder('لم يُسند بعد'),
                    TextEntry::make('address_details')->label('العنوان')->columnSpan(2),
                    TextEntry::make('address_landmark')->label('علامة مميزة')->placeholder('—'),
                    TextEntry::make('distance_km')->label('المسافة')->suffix(' كم'),
                ]),

            Section::make('الأصناف')
                ->schema([
                    TextEntry::make('items_list')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->listWithLineBreaks()
                        ->bulleted()
                        ->state(fn (Order $record) => $record->items
                            ->map(function ($i) {
                                $line = "{$i->quantity} × {$i->name}  —  "
                                    .number_format($i->line_total, 2).' د.ل';

                                $opts = $i->optionsText();

                                if ($opts !== '') {
                                    $line .= "  ({$opts})";
                                }

                                if (filled($i->note)) {
                                    $line .= "  [ملاحظة: {$i->note}]";
                                }

                                return $line;
                            })
                            ->all()),

                    TextEntry::make('notes')
                        ->label('ملاحظات الزبون')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),

            // تفصيل الفلوس: على الزبون، التوزيع، مين يحصّل ومين يسدد، والتسوية
            Section::make('الحساب والتوزيع')
                ->schema([View::make('filament.orders.money')]),

            Section::make('سجل الحالات')
                ->description('كل تغيير مسجّل بوقته ومنو عمله')
                ->collapsible()
                ->schema([
                    TextEntry::make('timeline')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->listWithLineBreaks()
                        ->state(function (Order $record) {
                            $logs = $record->statusLogs()->with('user')->get();

                            if ($logs->isEmpty()) {
                                return ['ما فيش سجل'];
                            }

                            $previous = null;

                            return $logs->map(function ($log) use (&$previous) {
                                $label = OrderStatus::tryFrom($log->to_status)?->label() ?? $log->to_status;
                                $time = $log->created_at?->format('H:i:s — d/m/Y') ?? '';
                                $who = $log->user?->name ?? 'النظام';

                                $gap = '';
                                if ($previous && $log->created_at) {
                                    $minutes = $previous->diffInMinutes($log->created_at);
                                    $gap = $minutes > 0 ? "  (بعد {$minutes} دقيقة)" : '';
                                }
                                $previous = $log->created_at;

                                $line = "{$time}  ●  {$label}  ●  بواسطة: {$who}{$gap}";

                                if (filled($log->note)) {
                                    $line .= "  ●  {$log->note}";
                                }

                                return $line;
                            })->all();
                        }),

                    TextEntry::make('cancel_reason')
                        ->label('سبب الإلغاء / الفشل')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
