<?php

namespace App\Filament\Resources\Settlements\Pages;

use App\Filament\Resources\Settlements\SettlementResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

class ViewSettlement extends Page
{
    use InteractsWithRecord;

    protected static string $resource = SettlementResource::class;

    protected string $view = 'filament.settlements.view';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(SettlementResource::canViewAny(), 403);
    }

    public function getTitle(): string
    {
        return 'تسوية '.$this->record->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            SettlementResource::printAction()->record($this->record),
            SettlementResource::printA4Action()->record($this->record),
            SettlementResource::cancelAction()->record($this->record)
                ->after(fn () => $this->record->refresh()),
        ];
    }

    protected function getViewData(): array
    {
        return [
            's' => $this->record,
            'orders' => $this->record->orders()->with('store', 'driver')->orderBy('delivered_at')->get(),
        ];
    }
}
