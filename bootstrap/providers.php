<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\MerchantPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    MerchantPanelProvider::class,
];
