<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Models\DeliveryZone;
use App\Models\DriverProfile;
use App\Models\User;
use App\Services\WalletService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['wallet', 'driverProfile']))
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('phone')
                    ->label('الهاتف')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('role')
                    ->label('الدور')
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state) => $state->label())
                    ->color(fn (UserRole $state) => match ($state) {
                        UserRole::Admin    => 'danger',
                        UserRole::Store    => 'warning',
                        UserRole::Driver   => 'info',
                        UserRole::Customer => 'gray',
                    }),

                TextColumn::make('points_balance')
                    ->label('النقاط')
                    ->numeric()
                    ->sortable()
                    ->toggleable()
                    ->visible(fn () => \App\Services\PointsService::enabled()),

                TextColumn::make('wallet.balance')
                    ->label('الرصيد')
                    ->formatStateUsing(fn ($state) => number_format((float) ($state ?? 0), 2).' د.ل')
                    ->color(fn ($state) => match (true) {
                        (float) ($state ?? 0) < 0  => 'danger',
                        (float) ($state ?? 0) > 0  => 'success',
                        default                    => 'gray',
                    })
                    ->description(fn (User $record) => $record->walletBalance() < 0
                        ? 'مدين للمنصة'
                        : null)
                    ->weight('bold')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('مفعّل')
                    ->boolean(),

                TextColumn::make('driverProfile.max_active_orders')
                    ->label('سعة الطلبات')
                    ->badge()
                    ->formatStateUsing(fn ($state, User $record) => $record->driverProfile
                        ? $state.' — '.$record->driverProfile->modeLabel()
                        : '—')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('driverProfile.zones.name')
                    ->label('مناطق العمل')
                    ->badge()
                    ->placeholder('كل المناطق')
                    ->toggleable(),

                IconColumn::make('driverProfile.is_approved')
                    ->label('سائق معتمد')
                    ->boolean()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('orders_count')
                    ->label('طلباته')
                    ->counts('orders')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('last_seen_at')
                    ->label('آخر ظهور')
                    ->since()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('تاريخ التسجيل')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('الدور')
                    ->options(fn () => collect(UserRole::cases())
                        ->mapWithKeys(fn ($r) => [$r->value => $r->label()])
                        ->all()),

                TrashedFilter::make()->label('المحذوفين'),
            ])
            ->recordActions([
                Action::make('topup')
                ->authorize(fn () => \App\Support\Perm::can('finance.manage'))
                    ->label('شحن محفظة')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->modalHeading(fn (User $record) => 'شحن محفظة: '.$record->name)
                    ->modalDescription(fn (User $record) => 'الرصيد الحالي: '
                        .number_format($record->walletBalance(), 2).' د.ل')
                    ->schema([
                        Select::make('type')
                            ->label('نوع العملية')
                            ->options([
                                'topup_cash' => 'شحن نقدي (استلمت فلوس)',
                                'adjustment' => 'تعديل يدوي',
                            ])
                            ->default('topup_cash')
                            ->required(),

                        TextInput::make('amount')
                            ->label('المبلغ (د.ل)')
                            ->numeric()
                            ->required()
                            ->minValue(0.01),

                        TextInput::make('note')
                            ->label('ملاحظة')
                            ->maxLength(200),
                    ])
                    ->action(function (User $record, array $data) {
                        app(WalletService::class)->credit(
                            $record,
                            (float) $data['amount'],
                            $data['type'],
                            null,
                            $data['note'] ?? null,
                            auth()->user()
                        );

                        Notification::make()
                            ->title('تم الشحن')
                            ->body('الرصيد الجديد: '
                                .number_format($record->fresh()->walletBalance(), 2).' د.ل')
                            ->success()
                            ->send();
                    }),

                ViewAction::make()->label('عرض'),

                ActionGroup::make([
                    EditAction::make()->label('تعديل'),

                    Action::make('deduct')
                ->authorize(fn () => \App\Support\Perm::can('finance.manage'))
                        ->label('خصم من المحفظة')
                        ->icon('heroicon-o-minus-circle')
                        ->color('warning')
                        ->schema([
                            TextInput::make('amount')
                                ->label('المبلغ (د.ل)')
                                ->numeric()
                                ->required()
                                ->minValue(0.01),
                            TextInput::make('note')
                                ->label('السبب')
                                ->required()
                                ->maxLength(200),
                        ])
                        ->action(function (User $record, array $data) {
                            app(WalletService::class)->debit(
                                $record,
                                (float) $data['amount'],
                                'adjustment',
                                null,
                                $data['note'],
                                auth()->user(),
                                true
                            );

                            Notification::make()->title('تم الخصم')->success()->send();
                        }),

                    Action::make('adjustPoints')
                        ->authorize(fn () => \App\Support\Perm::can('finance.manage'))
                        ->label('تعديل النقاط')
                        ->icon('heroicon-o-star')
                        ->color('warning')
                        ->visible(fn (User $record) => $record->role === UserRole::Customer)
                        ->modalDescription(fn (User $record) => 'الرصيد الحالي: '.$record->points_balance.' نقطة')
                        ->schema([
                            TextInput::make('points')->label('النقاط (+ زيادة · − خصم)')->numeric()->required()->integer(),
                            TextInput::make('note')->label('السبب')->maxLength(120),
                        ])
                        ->action(function (User $record, array $data) {
                            app(\App\Services\PointsService::class)->adjust($record, (int) $data['points'], $data['note'] ?? null, auth()->user());
                            Notification::make()->title('تم — الرصيد: '.$record->fresh()->points_balance.' نقطة')->success()->send();
                        }),

                    \App\Filament\Resources\Drivers\DriverCapacity::action(),

                    Action::make('approveDriver')
                ->authorize(fn () => \App\Support\Perm::can('users.manage'))
                        ->label('اعتماد السائق')
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn (User $record) => $record->role === UserRole::Driver
                            && $record->driverProfile
                            && ! $record->driverProfile->is_approved)
                        ->requiresConfirmation()
                        ->action(function (User $record) {
                            $record->driverProfile->update(['is_approved' => true]);
                            Notification::make()->title('تم اعتماد السائق')->success()->send();
                        }),

                    Action::make('toggleActive')
                ->authorize(fn ($record) => \App\Support\Perm::can('users.manage')
                    && ($record->role !== \App\Enums\UserRole::Admin || \App\Support\Perm::isSuper()))
                        ->label(fn (User $record) => $record->is_active ? 'إيقاف الحساب' : 'تفعيل الحساب')
                        ->icon(fn (User $record) => $record->is_active ? 'heroicon-o-no-symbol' : 'heroicon-o-check')
                        ->color(fn (User $record) => $record->is_active ? 'danger' : 'success')
                        ->requiresConfirmation()
                        ->action(fn (User $record) => $record->update(['is_active' => ! $record->is_active])),
                ])
                    ->label('المزيد')
                    ->icon('heroicon-o-ellipsis-vertical'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    \App\Filament\Resources\Drivers\DriverCapacity::bulkAction(),

                    DeleteBulkAction::make()->label('حذف'),
                ]),
            ]);
    }
}
