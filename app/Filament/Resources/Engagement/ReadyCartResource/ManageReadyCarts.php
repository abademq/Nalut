<?php

namespace App\Filament\Resources\Engagement\ReadyCartResource;

use App\Filament\Resources\Engagement\ReadyCartResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReadyCarts extends ManageRecords
{
    protected static string $resource = ReadyCartResource::class;

    public function getSubheading(): ?string
    {
        return 'تظهر في صفحة المتجر في التطبيق — الزبون يضغط «زيد للسلة» وتنضاف كل الأصناف بكمياتها.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('سلة جديدة')];
    }
}
