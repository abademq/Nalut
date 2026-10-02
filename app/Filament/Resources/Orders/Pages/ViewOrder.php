<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // بلاغ السائق المفتوح: القرار من صفحة الطلب نفسها
            OrdersTable::resolveIssueAction()->button(),
            EditAction::make(),
        ];
    }

    public function getSubheading(): ?string
    {
        $issue = $this->record->openIssue;

        return $issue
            ? "⚠ عليه بلاغ مفتوح ({$issue->ticket}): {$issue->reason_label} — الطلب موقوف لين تقرر من زر «مراجعة البلاغ»."
            : null;
    }
}
