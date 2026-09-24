<?php

namespace App\Filament\Resources\Coupons\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('كود الخصم')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->helperText('بالإنجليزي، مثال: WELCOME10 أو FREEDEL'),

                Select::make('type')
                    ->label('نوع العرض')
                    ->required()
                    ->default('percent')
                    ->live()
                    ->options([
                        'percent'       => 'نسبة مئوية من الطلب',
                        'fixed'         => 'مبلغ ثابت',
                        'free_delivery' => 'توصيل مجاني',
                    ]),

                TextInput::make('value')
                    ->label(fn ($get) => match ($get('type')) {
                        'percent' => 'النسبة %',
                        'fixed'   => 'المبلغ (د.ل)',
                        default   => 'القيمة',
                    })
                    ->numeric()
                    ->required()
                    ->default(0)
                    ->visible(fn ($get) => $get('type') !== 'free_delivery')
                    ->helperText('10 = خصم 10% أو 10 دينار حسب النوع'),

                TextInput::make('max_discount')
                    ->label('أقصى خصم (د.ل)')
                    ->numeric()
                    ->visible(fn ($get) => $get('type') === 'percent')
                    ->helperText('اتركه فاضي لو ما فيش حد أعلى'),

                TextInput::make('min_order')
                    ->label('أقل قيمة طلب (د.ل)')
                    ->numeric()
                    ->default(0)
                    ->helperText('مفيد مع التوصيل المجاني — مثلاً مجاني فوق 50 د.ل'),

                Select::make('store_id')
                    ->label('خاص بمتجر')
                    ->relationship('store', 'name')
                    ->searchable()
                    ->helperText('اتركه فاضي ليشتغل على كل المتاجر'),

                TextInput::make('usage_limit')
                    ->label('حد الاستخدام الكلي')
                    ->numeric()
                    ->helperText('اتركه فاضي = بلا حدود'),

                TextInput::make('per_user_limit')
                    ->label('حد الاستخدام لكل زبون')
                    ->numeric()
                    ->default(1),

                DateTimePicker::make('starts_at')->label('يبدأ في')->seconds(false),
                DateTimePicker::make('ends_at')->label('ينتهي في')->seconds(false),

                Toggle::make('is_active')->label('مفعّل')->default(true),
            ]);
    }
}
