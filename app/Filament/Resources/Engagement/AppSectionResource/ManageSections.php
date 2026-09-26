<?php

namespace App\Filament\Resources\Engagement\AppSectionResource;

use App\Filament\Resources\Engagement\AppSectionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSections extends ManageRecords
{
    protected static string $resource = AppSectionResource::class;

    public function getSubheading(): ?string
    {
        return 'الزبون يختار القسم أول (مطاعم، متاجر...) وتطلعله متاجر القسم بس. لو ما فيش أقسام، التطبيق يعرض كل المتاجر مع بعض.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('قسم جديد')];
    }
}
