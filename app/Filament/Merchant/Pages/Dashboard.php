<?php

namespace App\Filament\Merchant\Pages;

use App\Support\Merchant;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return 'متجري';
    }

    protected function getHeaderActions(): array
    {
        $open = (bool) Merchant::store()?->is_open;

        return [
            Action::make('toggleOpen')
                ->label($open ? 'المتجر مفتوح — اضغط للإغلاق' : 'المتجر مغلق — اضغط للفتح')
                ->icon($open ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                ->color($open ? 'success' : 'gray')
                ->requiresConfirmation()
                ->action(function () {
                    $store = Merchant::store();
                    abort_unless($store, 403);
                    $store->update(['is_open' => ! $store->is_open]);
                    Notification::make()->title($store->is_open ? 'المتجر مفتوح' : 'المتجر مغلق')->success()->send();
                }),
        ];
    }
}
