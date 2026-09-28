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
        $store = Merchant::store();
        $open = (bool) $store?->isAcceptingOrders();

        return [
            Action::make('toggleOpen')
                ->label(($open ? 'اضغط للإغلاق' : 'افتح توّا').' · '.($store?->statusText() ?? ''))
                ->icon($open ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                ->color($open ? 'success' : 'gray')
                ->requiresConfirmation()
                ->action(function () {
                    $store = Merchant::store();
                    abort_unless($store, 403);
                    $open = $store->toggleManual();
                    Notification::make()->title($open ? 'المتجر مفتوح' : 'المتجر مغلق')->body($store->statusText())->success()->send();
                }),
        ];
    }
}
