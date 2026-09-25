<?php

namespace App\Filament\Resources\WalletTransactions\Pages;

use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\User;
use App\Services\WalletService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('settlement')
                ->authorize(fn () => \App\Support\Perm::can('finance.manage'))
                ->label('تسوية نقدية')
                ->icon('heroicon-o-hand-raised')
                ->color('success')
                ->modalDescription('استعملها لمّا السائق يسلّم الكاش اللي عنده، '
                    .'أو لمّا تشحن محفظة زبون نقداً.')
                ->schema([
                    Select::make('user_id')
                        ->label('الحساب')
                        ->options(fn () => User::query()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($u) => [
                                $u->id => "{$u->name} — {$u->phone} ({$u->role->label()})",
                            ]))
                        ->searchable()
                        ->required()
                        ->live()
                        ->helperText(function ($get) {
                            if (! $get('user_id')) {
                                return null;
                            }
                            $user = User::find($get('user_id'));

                            return $user
                                ? 'الرصيد الحالي: '.number_format($user->walletBalance(), 2).' د.ل'
                                : null;
                        }),

                    TextInput::make('amount')
                        ->label('المبلغ (د.ل)')
                        ->numeric()
                        ->required()
                        ->minValue(0.01),

                    TextInput::make('note')
                        ->label('ملاحظة')
                        ->maxLength(200),
                ])
                ->action(function (array $data) {
                    $user = User::findOrFail($data['user_id']);

                    app(WalletService::class)->settle(
                        $user,
                        (float) $data['amount'],
                        'settlement',
                        $data['note'] ?? 'تسوية نقدية',
                        auth()->user()
                    );

                    Notification::make()->title('تمت التسوية')->success()->send();
                }),

            Action::make('payout')
                ->authorize(fn () => \App\Support\Perm::can('finance.manage'))
                ->label('صرف مستحقات')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('warning')
                ->modalDescription('استعملها لمّا تدفع للمتجر أو للسائق مستحقاته — '
                    .'ينقص الرصيد بالمبلغ المصروف.')
                ->schema([
                    Select::make('user_id')
                        ->label('الحساب')
                        ->options(fn () => User::whereIn('role', ['store', 'driver'])
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($u) => [
                                $u->id => "{$u->name} — ".number_format($u->walletBalance(), 2).' د.ل',
                            ]))
                        ->searchable()
                        ->required(),

                    TextInput::make('amount')
                        ->label('المبلغ المصروف (د.ل)')
                        ->numeric()
                        ->required()
                        ->minValue(0.01),

                    TextInput::make('note')
                        ->label('ملاحظة')
                        ->maxLength(200),
                ])
                ->action(function (array $data) {
                    $user = User::findOrFail($data['user_id']);

                    app(WalletService::class)->settle(
                        $user,
                        (float) $data['amount'],
                        'payout',
                        $data['note'] ?? 'صرف مستحقات',
                        auth()->user()
                    );

                    Notification::make()->title('تم الصرف')->success()->send();
                }),
        ];
    }
}
