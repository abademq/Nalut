<?php

namespace App\Filament\Resources\NotificationSettings\Pages;

use App\Filament\Pages\AdminAlertSettings;
use App\Filament\Resources\NotificationSettings\NotificationSettingResource;
use App\Models\NotificationSetting;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListNotificationSettings extends ListRecords
{
    protected static string $resource = NotificationSettingResource::class;

    public function getSubheading(): ?string
    {
        return 'شغّل أو أوقف الإشعار لكل دور حسب حالة الطلب. '
            .'التعديل يسري فوراً بدون حفظ. تنبيهات الإدارة نفسها (اللوحة وتطبيق الإدارة) من زر «تنبيهات لوحة التحكم».';
    }

    /** تبويب لكل دور */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('الكل')];
        foreach (NotificationSetting::ROLES as $role => $label) {
            $tabs[$role] = Tab::make($label)->modifyQueryUsing(fn (Builder $q) => $q->where('role', $role));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adminAlerts')
                ->label('تنبيهات لوحة التحكم')
                ->icon('heroicon-o-bell-alert')
                ->url(AdminAlertSettings::getUrl()),
        ];
    }
}
