<?php

namespace App\Filament\Merchant\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Merchant\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        $final = [OrderStatus::Delivered->value, OrderStatus::Cancelled->value, OrderStatus::Failed->value];

        return [
            'active' => Tab::make('الطلبات الحالية')
                ->modifyQueryUsing(fn (Builder $query) => $query->active())
                ->badge(fn () => OrderResource::getEloquentQuery()->active()->count() ?: null),
            'history' => Tab::make('السجل')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $final)),
        ];
    }
}
