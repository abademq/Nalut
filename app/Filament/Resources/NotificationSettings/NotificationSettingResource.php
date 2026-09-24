<?php

namespace App\Filament\Resources\NotificationSettings;

use App\Filament\Resources\NotificationSettings\Pages\ListNotificationSettings;
use App\Filament\Resources\NotificationSettings\Tables\NotificationSettingsTable;
use App\Models\NotificationSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class NotificationSettingResource extends Resource
{
    protected static ?string $model = NotificationSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return 'إعداد إشعار';
    }

    public static function getPluralModelLabel(): string
    {
        return 'إعدادات الإشعارات';
    }

    public static function getNavigationLabel(): string
    {
        return 'إعدادات الإشعارات';
    }

    public static function table(Table $table): Table
    {
        return NotificationSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNotificationSettings::route('/'),
        ];
    }
}
