<?php

namespace App\Filament\Resources\FailureReasons\Pages;

use App\Filament\Resources\FailureReasons\FailureReasonResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFailureReason extends EditRecord
{
    protected static string $resource = FailureReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
