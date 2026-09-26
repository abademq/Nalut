<?php

namespace App\Filament\Resources\StoreTypes\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class StoreTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('اسم النوع')->required()
                    ->helperText('مثال: مطاعم، صيدليات، بقالة'),
                \Filament\Forms\Components\Select::make('app_section_id')->label('القسم في التطبيق')
                    ->relationship('section', 'name')->native(false)->preload()
                    ->helperText('مثال: «مطاعم» تحت قسم المطاعم، «ملابس» تحت المتاجر الإلكترونية'),
                TextInput::make('icon')->label('الأيقونة')->helperText('اسم أيقونة أو رابط صورة (اختياري)'),
                TextInput::make('sort')->label('الترتيب')->numeric()->default(0),
                Toggle::make('is_active')->label('مفعّل')->default(true),
            ]);
    }
}
