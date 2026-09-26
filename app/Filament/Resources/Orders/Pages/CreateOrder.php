<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Address;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * إنشاء طلب يدوي (زبون اتصل بالتلفون مثلاً).
 * يمر عبر OrderService — نفس حسابات التطبيق تماماً:
 * المسافة والرسوم والعمولة والمخزون. السائق يتسند بعدين من جدول الطلبات.
 */
class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'طلب يدوي جديد';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label('الزبون')
                ->options(fn () => User::withRole('customer')
                    ->where('is_active', true)
                    ->get()
                    ->mapWithKeys(fn ($u) => [$u->id => "{$u->name} — {$u->phone}"]))
                ->searchable()
                ->required()
                ->live()
                ->helperText('الزبون لازم يكون مسجّل في النظام وعنده عنوان محفوظ'),

            Select::make('address_id')
                ->label('عنوان التوصيل')
                ->options(function ($get) {
                    if (! $get('customer_id')) {
                        return [];
                    }

                    return Address::where('user_id', $get('customer_id'))
                        ->get()
                        ->mapWithKeys(fn ($a) => [
                            $a->id => $a->landmark
                                ? "{$a->details} — {$a->landmark}"
                                : $a->details,
                        ]);
                })
                ->required()
                ->helperText('الإحداثيات تتاخذ من العنوان المحفوظ، وعليها تتحسب المسافة والرسوم'),

            Select::make('store_id')
                ->label('المتجر')
                ->relationship('store', 'name')
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn ($set) => $set('items', [])),

            Repeater::make('items')
                ->label('الأصناف')
                ->columnSpanFull()
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel('إضافة صنف')
                ->schema([
                    Select::make('product_id')
                        ->label('المنتج')
                        ->options(function ($get) {
                            $storeId = $get('../../store_id');
                            if (! $storeId) {
                                return [];
                            }

                            return Product::where('store_id', $storeId)
                                ->where('is_available', true)
                                ->get()
                                ->mapWithKeys(fn ($p) => [
                                    $p->id => $p->name.' — '.number_format($p->effectivePrice(), 2).' د.ل',
                                ]);
                        })
                        ->searchable()
                        ->required()
                        ->columnSpan(2),

                    TextInput::make('quantity')
                        ->label('الكمية')
                        ->numeric()
                        ->default(1)
                        ->minValue(1)
                        ->required(),

                    TextInput::make('note')
                        ->label('ملاحظة')
                        ->maxLength(200)
                        ->columnSpan(2),
                ])
                ->columns(5),

            Select::make('payment_method')
                ->label('طريقة الدفع')
                ->options([
                    'cash'   => 'نقداً عند الاستلام',
                    'wallet' => 'محفظة إلكترونية',
                    'card'   => 'بطاقة مصرفية',
                ])
                ->default('cash')
                ->required(),

            TextInput::make('coupon_code')
                ->label('كود الخصم')
                ->maxLength(30)
                ->helperText('اختياري'),

            Textarea::make('notes')
                ->label('ملاحظات')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $customer = User::findOrFail($data['customer_id']);

        return app(OrderService::class)->create($customer, [
            'store_id'       => $data['store_id'],
            'address_id'     => $data['address_id'],
            'payment_method' => $data['payment_method'] ?? 'cash',
            'coupon_code'    => $data['coupon_code'] ?? null,
            'notes'          => $data['notes'] ?? null,
            'items'          => array_map(fn ($i) => [
                'product_id' => $i['product_id'],
                'quantity'   => (int) ($i['quantity'] ?? 1),
                'note'       => $i['note'] ?? null,
            ], $data['items'] ?? []),
        ], auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم إنشاء الطلب — يقدر المتجر يقبله توّا';
    }
}
