<?php

namespace App\Filament\Merchant\Resources\MenuSections\Pages;

use App\Filament\Merchant\Resources\MenuSections\MenuSectionResource;
use App\Models\MenuSection;
use App\Support\Merchant;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMenuSections extends ManageRecords
{
    protected static string $resource = MenuSectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('قسم جديد')
                ->mutateDataUsing(function (array $data): array {
                    $data['store_id'] = Merchant::storeId();
                    $data['sort'] = (int) MenuSection::where('store_id', $data['store_id'])->max('sort') + 1;

                    return $data;
                }),
        ];
    }
}
