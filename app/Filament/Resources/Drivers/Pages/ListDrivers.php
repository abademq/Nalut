<?php

namespace App\Filament\Resources\Drivers\Pages;

use App\Filament\Resources\Drivers\DriverResource;
use Filament\Resources\Pages\ListRecords;

class ListDrivers extends ListRecords
{
    protected static string $resource = DriverResource::class;

    public function getSubheading(): ?string
    {
        return 'الجدول يتحدّث كل 30 ثانية. «موقع قديم» معناها السائق '
            .'معلّم متاح لكن تطبيقه ما يبعتش موقعه — غالباً أقفله.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
