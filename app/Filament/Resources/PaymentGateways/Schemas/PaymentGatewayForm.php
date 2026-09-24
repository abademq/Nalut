<?php

namespace App\Filament\Resources\PaymentGateways\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PaymentGatewayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم البوابة')
                    ->required(),

                Select::make('mode')
                    ->label('الوضع')
                    ->options([
                        'test' => 'تجريبي (للاختبار)',
                        'live' => 'مباشر (فلوس حقيقية)',
                    ])
                    ->required()
                    ->helperText('لا تخلّيه مباشر إلا بعد ما تختبر كامل'),

                Toggle::make('enabled_for_topup')
                    ->label('مفعّلة لشحن المحفظة'),

                Toggle::make('enabled_for_order')
                    ->label('مفعّلة للدفع المباشر للطلب'),

                TextInput::make('min_amount')
                    ->label('أقل مبلغ (د.ل)')
                    ->numeric()
                    ->required()
                    ->default(1),

                TextInput::make('max_amount')
                    ->label('أقصى مبلغ (د.ل)')
                    ->numeric()
                    ->helperText('اتركه فاضي = بلا حدود'),

                TextInput::make('credentials.api_key')
                    ->label('API Key')
                    ->password()
                    ->revealable()
                    ->columnSpanFull()
                    ->helperText('من لوحة بلوتو: API Management → API Keys & Tokens'),

                TextInput::make('credentials.access_token')
                    ->label('Access Token')
                    ->password()
                    ->revealable()
                    ->columnSpanFull(),

                TextInput::make('credentials.secret_key')
                    ->label('Secret Key')
                    ->password()
                    ->revealable()
                    ->columnSpanFull()
                    ->helperText('مطلوب للبوابات اللي ترجع عبر صفحة دفع — '
                        .'بيه نتحقق إن رد الدفع جاي من بلوتو فعلاً'),

                TextInput::make('sort')
                    ->label('الترتيب')
                    ->numeric()
                    ->default(0),
            ]);
    }
}
