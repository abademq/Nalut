<?php

namespace App\Filament\Resources\PaymentGateways\Pages;

use App\Filament\Resources\PaymentGateways\PaymentGatewayResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentGateways extends ListRecords
{
    protected static string $resource = PaymentGatewayResource::class;

    public function getSubheading(): ?string
    {
        return 'المفاتيح مخزّنة مشفّرة. البوابة ما تظهرش للزبون إلا لو مفاتيحها كاملة والمفتاح مفعّل.';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
