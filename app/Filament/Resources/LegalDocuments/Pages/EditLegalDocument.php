<?php

namespace App\Filament\Resources\LegalDocuments\Pages;

use App\Filament\Resources\LegalDocuments\LegalDocumentResource;
use Filament\Resources\Pages\EditRecord;

class EditLegalDocument extends EditRecord
{
    protected static string $resource = LegalDocumentResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // «تغيير جوهري» ← نسخة جديدة والكل يوافقو من جديد
        if ($this->data['require_reconsent'] ?? false) {
            $data['version'] = $this->getRecord()->version + 1;
        }

        return $data;
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->getResource()::getUrl('index');
    }
}
