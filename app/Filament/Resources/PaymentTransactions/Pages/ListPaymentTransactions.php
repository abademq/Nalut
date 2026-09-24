<?php

namespace App\Filament\Resources\PaymentTransactions\Pages;

use App\Filament\Resources\PaymentTransactions\PaymentTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentTransactions extends ListRecords
{
    protected static string $resource = PaymentTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
