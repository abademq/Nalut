<?php

namespace App\Filament\Resources\Messaging\ReportSubscriptionResource;

use App\Filament\Resources\Messaging\ReportSubscriptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageReports extends ManageRecords
{
    protected static string $resource = ReportSubscriptionResource::class;

    public function getSubheading(): ?string
    {
        return 'ملخص الطلبات والمبيعات والرصيد وآخر تسكير — يوصل على واتساب أو SMS لرقم المتجر أو أي رقم.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('تقرير جديد')];
    }
}
