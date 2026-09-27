<?php

namespace App\Filament\Resources\WalletTransactions\Pages;

use App\Filament\Pages\SettlementDesk;
use App\Filament\Resources\WalletTransactions\WalletTransactionResource;
use App\Models\User;
use App\Services\WalletService;
use App\Support\Perm;
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
            // تسويات المتاجر والسائقين انتقلت لتبويب «التسويات» (بواصل مطبوع)
            Action::make('settlements')
                ->label('تسويات المتاجر والسائقين')
                ->icon('heroicon-o-scale')
                ->color('gray')
                ->visible(fn () => SettlementDesk::canAccess())
                ->url(SettlementDesk::getUrl()),

            Action::make('topupCash')
                ->authorize(fn () => Perm::can('finance.manage'))
                ->label('شحن نقدي لزبون')
                ->icon('heroicon-o-hand-raised')
                ->color('success')
                ->modalDescription('الزبون سلّمك فلوس نقداً وتبي تضيفها لمحفظته.')
                ->schema([
                    Select::make('user_id')
                        ->label('الزبون')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => User::withRole('customer')
                            ->where(fn ($q) => $q->where('name', 'like', "%$search%")->orWhere('phone', 'like', "%$search%"))
                            ->limit(30)->get()
                            ->mapWithKeys(fn ($u) => [$u->id => "{$u->name} — {$u->phone}"]))
                        ->getOptionLabelUsing(fn ($value) => ($u = User::find($value)) ? "{$u->name} — {$u->phone}" : null)
                        ->required()
                        ->live()
                        ->helperText(fn ($get) => $get('user_id') && ($u = User::find($get('user_id')))
                            ? 'الرصيد الحالي: '.number_format($u->walletBalance(), 2).' د.ل' : null),

                    TextInput::make('amount')->label('المبلغ (د.ل)')->numeric()->required()->minValue(0.01),
                    TextInput::make('note')->label('ملاحظة')->maxLength(200),
                ])
                ->action(function (array $data) {
                    app(WalletService::class)->credit(
                        User::findOrFail($data['user_id']),
                        (float) $data['amount'],
                        'topup_cash',
                        null,
                        $data['note'] ?? 'شحن نقدي',
                        auth()->user()
                    );

                    Notification::make()->title('تم الشحن')->success()->send();
                }),
        ];
    }
}
