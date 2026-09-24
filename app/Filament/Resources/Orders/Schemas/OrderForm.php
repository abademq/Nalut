<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * استمارة تعديل الطلب — أغلب الحقول للعرض فقط.
 * تغيير الحالة يتم من أزرار الجدول باش يمر عبر OrderService.
 */
class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('رقم الطلب')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('status')
                    ->label('الحالة')
                    ->formatStateUsing(fn ($state) => $state instanceof OrderStatus ? $state->label() : $state)
                    ->disabled()
                    ->dehydrated(false)
                    ->helperText('غيّر الحالة من أزرار جدول الطلبات'),

                Select::make('customer_id')
                    ->label('الزبون')
                    ->relationship('customer', 'name')
                    ->disabled()
                    ->dehydrated(false),

                Select::make('store_id')
                    ->label('المتجر')
                    ->relationship('store', 'name')
                    ->disabled()
                    ->dehydrated(false),

                Select::make('driver_id')
                    ->label('السائق')
                    ->options(fn () => User::where('role', 'driver')
                        ->where('is_active', true)
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('لم يُسند بعد'),

                Select::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options(fn () => collect(PaymentMethod::cases())
                        ->mapWithKeys(fn ($m) => [$m->value => $m->label()])
                        ->all()),

                Toggle::make('is_paid')
                    ->label('تم الدفع'),

                TextInput::make('customer_phone')
                    ->label('هاتف الزبون')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('address_details')
                    ->label('العنوان')
                    ->columnSpanFull()
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('address_landmark')
                    ->label('علامة مميزة')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('distance_km')
                    ->label('المسافة (كم)')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('subtotal')
                    ->label('مجموع الأصناف (د.ل)')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('delivery_fee')
                    ->label('رسوم التوصيل (د.ل)')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('discount')
                    ->label('الخصم (د.ل)')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('total')
                    ->label('الإجمالي (د.ل)')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('commission_amount')
                    ->label('عمولة المنصة (د.ل)')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('store_earning')
                    ->label('صافي المتجر (د.ل)')
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('driver_earning')
                    ->label('أجرة السائق (د.ل)')
                    ->disabled()
                    ->dehydrated(false),

                Textarea::make('notes')
                    ->label('ملاحظات الزبون')
                    ->rows(2)
                    ->columnSpanFull(),

                TextInput::make('cancel_reason')
                    ->label('سبب الإلغاء')
                    ->columnSpanFull()
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn ($record) => filled($record?->cancel_reason)),
            ]);
    }
}
