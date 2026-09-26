<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** دور سائق جديد؟ ملف السائق ينشأ (يستنى «اعتماد السائق») */
    protected function afterCreate(): void
    {
        $this->record->ensureDriverProfile();
    }
}
