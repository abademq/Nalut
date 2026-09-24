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
                TextInput::make('icon')->label('الأيقونة')->helperText('اسم أيقونة أو رابط صورة (اختياري)'),
                TextInput::make('sort')->label('الترتيب')->numeric()->default(0),
                Toggle::make('is_active')->label('مفعّل')->default(true),
            ]);
    }
}
