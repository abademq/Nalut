<?php

namespace App\Filament\Resources\LegalDocuments\Pages;

use App\Filament\Resources\LegalDocuments\LegalDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListLegalDocuments extends ListRecords
{
    protected static string $resource = LegalDocumentResource::class;

    public function getSubheading(): ?string
    {
        return 'مسودات جاهزة — راجعها مع محامي قبل الإطلاق. الروابط العامة تنحط في Google Play وApp Store.';
    }
}
