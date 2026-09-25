<?php

namespace App\Filament\Resources\AppTexts\Pages;

use App\Filament\Resources\AppTexts\AppTextResource;
use App\Models\AppText;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAppTexts extends ListRecords
{
    protected static string $resource = AppTextResource::class;

    public function getSubheading(): ?string
    {
        return 'اكتب نص بديل لأي نص في التطبيقات أو الإشعارات. التعديل يوصل للتطبيقات أول ما تنفتح — بدون تحديث.';
    }

    public function getTabs(): array
    {
        $tabs = [];

        foreach (AppText::APPS as $app => $label) {
            $tabs[$app] = Tab::make($label)
                ->badge(fn () => AppText::for($app)->whereNotNull('value')->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('app', $app));
        }

        return $tabs;
    }
}
