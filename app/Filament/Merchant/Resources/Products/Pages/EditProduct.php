<?php

namespace App\Filament\Merchant\Resources\Products\Pages;

use App\Filament\Merchant\Resources\Products\ProductResource;
use App\Models\MenuSection;
use App\Models\Product;
use App\Support\Merchant;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->label('حذف الصنف')];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // ما يقدرش ينقل الصنف لمتجر ثاني ولا لقسم مش تابعله
        unset($data['store_id']);
        if (array_key_exists('menu_section_id', $data)) {
            $data['menu_section_id'] = MenuSection::where('store_id', Merchant::storeId())
                ->whereKey($data['menu_section_id'] ?? 0)->value('id');
        }

        // نفس قاعدة التطبيق: الصنف اللي خلص ما يتفتحش إلا بكمية جديدة
        $record = $this->getRecord();
        $wantsOpen = ($data['is_available'] ?? false) && ! $record->is_available;
        $track = (bool) ($data['track_stock'] ?? $record->track_stock);
        $qty = (int) ($data['stock_quantity'] ?? $record->stock_quantity);
        if ($wantsOpen && $track && $qty <= 0) {
            throw ValidationException::withMessages(['data.stock_quantity' => Product::OUT_OF_STOCK_MESSAGE]);
        }

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
