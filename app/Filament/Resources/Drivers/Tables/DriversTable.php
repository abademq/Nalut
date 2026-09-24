<?php

namespace App\Filament\Resources\Drivers\Tables;

use App\Models\User;
use App\Services\WalletService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DriversTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->poll('30s')
            ->columns([
                TextColumn::make('name')
                    ->label('السائق')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (User $record) => $record->phone),

                TextColumn::make('availability')
                    ->label('الحالة')
                    ->badge()
                    ->state(function (User $record) {
                        $p = $record->driverProfile;

                        if (! $record->is_active) {
                            return 'موقوف';
                        }

                        if (! $p) {
                            return 'بلا ملف';
                        }

                        if (! $p->is_approved) {
                            return 'بانتظار الاعتماد';
                        }

                        if (! $p->is_online) {
                            return 'غير متاح';
                        }

                        return $p->isReallyOnline() ? 'متاح' : 'متاح (موقع قديم)';
                    })
                    ->color(fn ($state) => match ($state) {
                        'متاح'              => 'success',
                        'متاح (موقع قديم)'  => 'warning',
                        'غير متاح'          => 'gray',
                        'بانتظار الاعتماد'  => 'info',
                        default             => 'danger',
                    }),

                TextColumn::make('location_age')
                    ->label('آخر موقع')
                    ->state(function (User $record) {
                        $location = $record->driverProfile?->liveLocation();

                        if (! $location) {
                            return 'ما فيش';
                        }

                        // الكاش TTL دقيقتين، فالثواني أدق من الدقائق هنا
                        $seconds = now()->timestamp - $location['at'];

                        if ($seconds < 30) {
                            return 'توّا';
                        }

                        if ($seconds < 120) {
                            return "قبل {$seconds} ثانية";
                        }

                        return 'قبل '.(int) floor($seconds / 60).' دقيقة';
                    })
                    ->color(fn (User $record) => $record->driverProfile?->isReallyOnline()
                        ? 'success'
                        : 'gray'),

                TextColumn::make('balance')
                    ->label('الحساب')
                    ->state(fn (User $record) => (float) ($record->wallet?->balance ?? 0))
                    ->formatStateUsing(function ($state) {
                        $v = number_format(abs((float) $state), 2);

                        if ((float) $state > 0) {
                            return "له {$v} د.ل";
                        }

                        if ((float) $state < 0) {
                            return "عليه {$v} د.ل";
                        }

                        return 'مصفّى';
                    })
                    ->badge()
                    ->color(fn ($state) => match (true) {
                        (float) $state > 0 => 'success',
                        (float) $state < 0 => 'danger',
                        default            => 'gray',
                    })
                    ->sortable(query: fn (Builder $q, string $direction) => $q
                        ->leftJoin('wallets', 'wallets.user_id', '=', 'users.id')
                        ->orderBy('wallets.balance', $direction)
                        ->select('users.*')),

                TextColumn::make('active_orders')
                    ->label('طلبات ماشية')
                    ->state(fn (User $record) => $record->driverProfile?->activeOrders()->count() ?? 0)
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'info' : 'gray'),

                // أسماء بدون نقطة عن قصد: اسم فيه نقطة يخلي فيلامنت
                // يحاول يمشي على العلاقة، وتنكسر الصفحة لو كانت فاضية
                TextColumn::make('delivered')
                    ->label('توصيلات')
                    ->state(fn (User $record) => (int) ($record->driverProfile?->delivered_count ?? 0)),

                TextColumn::make('rating')
                    ->label('التقييم')
                    ->state(function (User $record) {
                        $p = $record->driverProfile;

                        if (! $p || ! $p->rating_count) {
                            return 'جديد';
                        }

                        return number_format((float) $p->rating_avg, 1)
                            ." ({$p->rating_count})";
                    }),

                TextColumn::make('zones_list')
                    ->label('مناطق العمل')
                    ->state(fn (User $record) => $record->driverProfile
                        ?->zones->pluck('name')->implode('، ') ?: 'كل المناطق')
                    ->wrap()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('الحالة')
                    ->options([
                        'online'   => 'متاح توّا',
                        'offline'  => 'غير متاح',
                        'pending'  => 'بانتظار الاعتماد',
                        'blocked'  => 'موقوف',
                    ])
                    ->query(function (Builder $query, array $data) {
                        return match ($data['value'] ?? null) {
                            'online' => $query->where('is_active', true)
                                ->whereHas('driverProfile', fn ($q) => $q
                                    ->where('is_approved', true)
                                    ->where('is_online', true)),
                            'offline' => $query->where('is_active', true)
                                ->whereHas('driverProfile', fn ($q) => $q
                                    ->where('is_approved', true)
                                    ->where('is_online', false)),
                            'pending' => $query->whereHas('driverProfile',
                                fn ($q) => $q->where('is_approved', false)),
                            'blocked' => $query->where('is_active', false),
                            default   => $query,
                        };
                    }),

                Filter::make('owes')
                    ->label('عليهم كاش للمنصة')
                    ->query(fn (Builder $q) => $q->whereHas('wallet',
                        fn ($w) => $w->where('balance', '<', 0))),

                Filter::make('credit')
                    ->label('لهم مستحقات')
                    ->query(fn (Builder $q) => $q->whereHas('wallet',
                        fn ($w) => $w->where('balance', '>', 0))),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('approve')
                        ->label('اعتماد السائق')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn (User $record) => $record->driverProfile
                            && ! $record->driverProfile->is_approved)
                        ->requiresConfirmation()
                        ->action(function (User $record) {
                            $record->driverProfile->update(['is_approved' => true]);

                            Notification::make()
                                ->title('تم اعتماد السائق')
                                ->success()
                                ->send();
                        }),

                    Action::make('forceOffline')
                        ->label('جعله غير متاح')
                        ->icon('heroicon-o-pause-circle')
                        ->color('warning')
                        ->visible(fn (User $record) => $record->driverProfile?->is_online)
                        ->requiresConfirmation()
                        ->modalDescription('يستعمل لمّا يقفل السائق التطبيق '
                            .'بدون ما يوقف «متاح».')
                        ->action(function (User $record) {
                            $record->driverProfile->update(['is_online' => false]);

                            Notification::make()
                                ->title('صار غير متاح')
                                ->success()
                                ->send();
                        }),

                    Action::make('settle')
                        ->label('تسوية الحساب')
                        ->icon('heroicon-o-banknotes')
                        ->color('info')
                        ->schema([
                            TextInput::make('amount')
                                ->label('المبلغ المستلم نقداً (د.ل)')
                                ->numeric()
                                ->required()
                                ->minValue(0.01)
                                ->helperText('المبلغ اللي سلّمه السائق للإدارة'),

                            TextInput::make('note')
                                ->label('ملاحظة')
                                ->maxLength(120),
                        ])
                        ->action(function (User $record, array $data) {
                            app(WalletService::class)->credit(
                                $record,
                                (float) $data['amount'],
                                'settlement',
                                null,
                                $data['note'] ?? 'تسوية نقدية'
                            );

                            Notification::make()
                                ->title('تمت التسوية')
                                ->body('الرصيد الجديد: '
                                    .number_format(
                                        app(WalletService::class)->balance($record), 2)
                                    .' د.ل')
                                ->success()
                                ->send();
                        }),

                    Action::make('toggleActive')
                        ->label(fn (User $record) => $record->is_active
                            ? 'إيقاف الحساب'
                            : 'تفعيل الحساب')
                        ->icon('heroicon-o-no-symbol')
                        ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                        ->requiresConfirmation()
                        ->action(function (User $record) {
                            $record->update(['is_active' => ! $record->is_active]);

                            if (! $record->is_active && $record->driverProfile) {
                                $record->driverProfile->update(['is_online' => false]);
                            }

                            Notification::make()
                                ->title($record->is_active ? 'تم التفعيل' : 'تم الإيقاف')
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }
}
