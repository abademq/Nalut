<?php

namespace App\Filament\Resources\Stores\Schemas;

use App\Filament\Forms\MapPicker;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class StoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('صاحب المتجر')
                    ->options(fn () => User::withRole('store')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->helperText('لازم يكون حساب دوره «متجر»'),

                TextInput::make('name')
                    ->label('اسم المتجر')
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set) => $set('slug', Str::slug($state))),

                TextInput::make('slug')
                    ->label('المعرّف (بالإنجليزي)')
                    ->required()
                    ->unique(ignoreRecord: true),

                Select::make('store_type_id')
                    ->label('نوع المتجر')
                    ->relationship('type', 'name')
                    ->searchable(),

                Select::make('delivery_zone_id')
                    ->label('منطقة التوصيل')
                    ->relationship('zone', 'name')
                    ->searchable(),

                TextInput::make('phone')
                    ->label('رقم الهاتف')
                    ->tel(),

                TextInput::make('address')
                    ->label('العنوان')
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label('الوصف')
                    ->rows(3)
                    ->columnSpanFull(),

                FileUpload::make('logo')
                    ->label('الشعار')
                    ->image()
                    ->disk('public')
                    ->directory('stores'),

                FileUpload::make('cover')
                    ->label('صورة الغلاف')
                    ->image()
                    ->disk('public')
                    ->directory('stores'),

                // الموقع إلزامي: منه تتحسب مسافة التوصيل ورسومه، ويظهر للسائق والزبون
                MapPicker::make('lat', 'lng', null, true, 'موقع المتجر'),

                TextInput::make('lat')
                    ->label('خط العرض')
                    ->numeric()
                    ->required()
                    ->minValue(-90)->maxValue(90)
                    ->validationMessages(['required' => 'حدّد موقع المتجر على الخريطة.']),

                TextInput::make('lng')
                    ->label('خط الطول')
                    ->numeric()
                    ->required()
                    ->minValue(-180)->maxValue(600)
                    ->validationMessages(['required' => 'حدّد موقع المتجر على الخريطة.']),

                TextInput::make('commission_percent')
                    ->label('نسبة العمولة %')
                    ->numeric()
                    ->required()
                    ->default(fn () => \App\Support\Options::get('delivery.default_commission_percent'))
                    ->minValue(0)
                    ->maxValue(100),

                TextInput::make('min_order')
                    ->label('أقل قيمة طلب (د.ل)')
                    ->numeric()
                    ->required()
                    ->default(0),

                TextInput::make('prep_time_minutes')
                    ->label('وقت التحضير (دقيقة)')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->maxValue(600)
                    ->default(fn () => \App\Support\Options::get('orders.default_prep_minutes')),

                TimePicker::make('opens_at')
                    ->label('وقت الفتح')
                    // ساعة حائط بتوقيت ليبيا — بدون تحويل منطقة زمنية
                    ->timezone('UTC')
                    ->seconds(false),

                TimePicker::make('closes_at')
                    ->label('وقت الغلق')
                    ->timezone('UTC')
                    ->seconds(false),

                Toggle::make('is_open')
                    ->label('مفتوح توّا')
                    ->default(true),

                Toggle::make('is_active')
                    ->label('مفعّل')
                    ->default(true)
                    ->helperText('لو أوقفته، ما يظهرش للزبائن نهائياً'),
            ]);
    }
}
