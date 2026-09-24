<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * خريطة حيّة للسائقين — البيانات تجي من DriverMapController
 * كل 15 ثانية، فالصفحة نفسها ما تستعلمش من قاعدة البيانات.
 */
class DriversMap extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'العمليات';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.drivers-map';

    public static function getNavigationLabel(): string
    {
        return 'خريطة السائقين';
    }

    public function getTitle(): string
    {
        return 'خريطة السائقين';
    }
}
