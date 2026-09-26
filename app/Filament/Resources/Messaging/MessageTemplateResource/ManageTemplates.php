<?php

namespace App\Filament\Resources\Messaging\MessageTemplateResource;

use App\Filament\Resources\Messaging\MessageTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTemplates extends ManageRecords
{
    protected static string $resource = MessageTemplateResource::class;

    public function getSubheading(): ?string
    {
        return 'واتساب ورسالة ما يقبلوش نص حر للتسويق والتقارير — اعتمد القالب عند المزوّد أول، وبعدها اربطه هنا.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('قالب جديد')];
    }
}
