<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('30s')
            ->columns([
                TextColumn::make('code')
                    ->label('رقم الطلب')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('customer.name')
                    ->label('الزبون')
                    ->searchable()
                    ->description(fn (Order $record) => $record->customer_phone),

                TextColumn::make('store.name')
                    ->label('المتجر')
                    ->searchable(),

                TextColumn::make('driver.name')
                    ->label('السائق')
                    ->placeholder('لم يُسند بعد')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state) => $state->label())
                    ->color(fn (OrderStatus $state) => match ($state) {
                        OrderStatus::Pending                         => 'warning',
                        OrderStatus::Accepted, OrderStatus::Preparing => 'info',
                        OrderStatus::Ready, OrderStatus::Assigned    => 'primary',
                        OrderStatus::PickedUp, OrderStatus::OnTheWay => 'info',
                        OrderStatus::Delivered                       => 'success',
                        OrderStatus::Cancelled, OrderStatus::Failed  => 'danger',
                    }),

                TextColumn::make('total')
                    ->label('الإجمالي')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل')
                    ->sortable(),

                TextColumn::make('delivery_fee')
                    ->label('التوصيل')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('commission_amount')
                    ->label('العمولة')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' د.ل')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('payment_method')
                    ->label('الدفع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->label())
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('distance_km')
                    ->label('المسافة')
                    ->suffix(' كم')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('وقت الطلب')
                    ->dateTime('H:i - d/m')
                    ->sortable(),

                TextColumn::make('delivered_at')
                    ->label('وقت التسليم')
                    ->dateTime('H:i - d/m')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(fn () => collect(OrderStatus::cases())
                        ->mapWithKeys(fn ($c) => [$c->value => $c->label()])
                        ->all())
                    ->multiple(),

                SelectFilter::make('store_id')
                    ->label('المتجر')
                    ->relationship('store', 'name')
                    ->searchable(),

                SelectFilter::make('driver_id')
                    ->label('السائق')
                    ->relationship('driver', 'name')
                    ->searchable(),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),

                Action::make('changeStatus')
                    ->label('تغيير الحالة')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->modalHeading('تغيير حالة الطلب')
                    ->modalDescription('الحالات المعلّمة بـ ⚠ خارج المسار الطبيعي — تحتاج تأكيد إضافي.')
                    ->modalSubmitActionLabel('تأكيد التغيير')
                    ->schema([
                        Select::make('status')
                            ->label('الحالة الجديدة')
                            ->options(fn ($record) => collect(OrderStatus::cases())
                                ->reject(fn ($s) => $s === $record->status)
                                ->mapWithKeys(fn ($s) => [
                                    $s->value => $record->status->canMoveTo($s)
                                        ? $s->label()
                                        : '⚠ '.$s->label(),
                                ])
                                ->all())
                            ->required()
                            ->live()
                            ->helperText(fn ($record) => 'الحالة الحالية: '.$record->status->label()),

                        Checkbox::make('force')
                            ->label('أؤكد هذا التغيير الاستثنائي')
                            ->helperText('الانتقال خارج المسار الطبيعي للطلب. '
                                .'بيتسجّل في السجل كتغيير إداري استثنائي باسمك.')
                            ->accepted()
                            ->visible(fn ($get, $record) => self::isAbnormal($record, $get('status'))),

                        TextInput::make('prep_time_minutes')
                            ->label('وقت التحضير (دقيقة)')
                            ->numeric()
                            ->default(20)
                            ->minValue(5)
                            ->maxValue(180)
                            ->visible(fn ($get) => $get('status') === OrderStatus::Accepted->value),

                        Select::make('driver_id')
                            ->label('السائق')
                            ->options(fn () => User::where('role', 'driver')
                                ->where('is_active', true)
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->required(fn ($get) => $get('status') === OrderStatus::Assigned->value)
                            ->visible(fn ($get) => $get('status') === OrderStatus::Assigned->value),

                        TextInput::make('reason')
                            ->label('السبب')
                            ->maxLength(200)
                            ->required(fn ($get, $record) => self::isAbnormal($record, $get('status'))
                                || in_array(
                                    $get('status'),
                                    [OrderStatus::Cancelled->value, OrderStatus::Failed->value],
                                    true
                                ))
                            ->visible(fn ($get, $record) => self::isAbnormal($record, $get('status'))
                                || in_array(
                                    $get('status'),
                                    [OrderStatus::Cancelled->value, OrderStatus::Failed->value],
                                    true
                                )),
                    ])
                    ->action(function (Order $record, array $data) {
                        $to = OrderStatus::from($data['status']);

                        self::move($record, $to, [
                            'prep_time_minutes' => $data['prep_time_minutes'] ?? null,
                            'driver_id'         => $data['driver_id'] ?? null,
                            'reason'            => $data['reason'] ?? null,
                            'force'             => (bool) ($data['force'] ?? false),
                        ]);
                    }),
            ]);
    }

    /** هل الانتقال المختار خارج المسار الطبيعي؟ */
    private static function isAbnormal(?Order $record, $status): bool
    {
        if (! $record || ! $status) {
            return false;
        }

        $target = OrderStatus::tryFrom($status);

        return $target !== null && ! $record->status->canMoveTo($target);
    }

    private static function move(Order $order, OrderStatus $to, array $extra = []): void
    {
        $force = (bool) ($extra['force'] ?? false);
        $extra = array_filter($extra, fn ($v) => ! is_null($v));
        $extra['force'] = $force;

        try {
            app(OrderService::class)->transition($order, $to, auth()->user(), $extra);

            Notification::make()
                ->title('تم التحديث: '.$to->label())
                ->success()
                ->send();
        } catch (ValidationException $e) {
            Notification::make()
                ->title('ما نجحش التغيير')
                ->body(collect($e->errors())->flatten()->first())
                ->danger()
                ->send();
        }
    }
}
