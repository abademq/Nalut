<?php

namespace App\Filament\Resources\NotificationSettings\Pages;

use App\Filament\Resources\NotificationSettings\NotificationSettingResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationSettings extends ListRecords
{
    protected static string $resource = NotificationSettingResource::class;

    public function getSubheading(): ?string
    {
        return 'شغّل أو أوقف الإشعار لكل دور حسب حالة الطلب. '
            .'التعديل يسري فوراً بدون حفظ.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
