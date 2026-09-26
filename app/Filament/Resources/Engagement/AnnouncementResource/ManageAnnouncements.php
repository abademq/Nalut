<?php

namespace App\Filament\Resources\Engagement\AnnouncementResource;

use App\Filament\Resources\Engagement\AnnouncementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageAnnouncements extends ManageRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('شريط جديد')];
    }
}
