<?php

namespace App\Filament\Merchant\Resources\Products\Pages;

use App\Filament\Merchant\Resources\Products\ProductResource;
use App\Models\MenuSection;
use App\Support\Merchant;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /** المتجر دائماً متجره — مهما جا في الطلب */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['store_id'] = Merchant::storeId();
        $data['menu_section_id'] = MenuSection::where('store_id', $data['store_id'])
            ->whereKey($data['menu_section_id'] ?? 0)->value('id');

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
