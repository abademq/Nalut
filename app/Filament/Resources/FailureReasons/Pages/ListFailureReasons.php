<?php

namespace App\Filament\Resources\FailureReasons\Pages;

use App\Filament\Resources\FailureReasons\FailureReasonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFailureReasons extends ListRecords
{
    protected static string $resource = FailureReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
