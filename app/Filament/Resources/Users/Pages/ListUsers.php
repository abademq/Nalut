<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\WalletService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;



    protected function getHeaderActions(): array
    {
        return [
            Action::make('topupByPhone')
                ->authorize(fn () => \App\Support\Perm::can('finance.manage'))
                ->label('شحن محفظة برقم الهاتف')
                ->icon('heroicon-o-device-phone-mobile')
                ->color('success')
                ->modalDescription('اكتب رقم الزبون والمبلغ — مفيد لمّا الزبون يكون واقف قدامك.')
                ->schema([
                    TextInput::make('phone')
                        ->label('رقم الهاتف')
                        ->required()
                        ->tel()
                        ->helperText('بصيغة 09XXXXXXXX'),

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
                    $user = User::where('phone', trim($data['phone']))->first();

                    if (! $user) {
                        throw ValidationException::withMessages([
                            'phone' => 'ما فيش مستخدم بهذا الرقم.',
                        ]);
                    }

                    app(WalletService::class)->credit(
                        $user,
                        (float) $data['amount'],
                        'topup_cash',
                        null,
                        $data['note'] ?? 'شحن نقدي',
                        auth()->user()
                    );

                    Notification::make()
                        ->title('تم شحن محفظة '.$user->name)
                        ->body('الرصيد الجديد: '
                            .number_format($user->fresh()->walletBalance(), 2).' د.ل')
                        ->success()
                        ->send();
                }),

            CreateAction::make()->label('مستخدم جديد'),
        ];
    }
}
