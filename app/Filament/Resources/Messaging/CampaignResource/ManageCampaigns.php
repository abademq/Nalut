<?php

namespace App\Filament\Resources\Messaging\CampaignResource;

use App\Filament\Resources\Messaging\CampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCampaigns extends ManageRecords
{
    protected static string $resource = CampaignResource::class;

    public function getSubheading(): ?string
    {
        return 'رسائل تسويقية لأرقام الزبائن على واتساب أو SMS. الزبائن اللي رافضين الرسائل التسويقية ما يوصلهمش شي.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('حملة جديدة')
                ->mutateDataUsing(fn (array $data) => $data + ['created_by' => auth()->id(), 'status' => 'draft']),
        ];
    }
}
