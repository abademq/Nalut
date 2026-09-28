<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('المفتوحة')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', 'closed'))
                ->badge(fn () => Ticket::where('status', 'open')->count() ?: null)->badgeColor('danger'),
            'closed' => Tab::make('المقفولة')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'closed')),
            'all' => Tab::make('الكل'),
        ];
    }
}
