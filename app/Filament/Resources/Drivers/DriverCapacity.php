<?php

namespace App\Filament\Resources\Drivers;

use App\Enums\UserRole;
use App\Models\DeliveryZone;
use App\Models\DriverProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * سعة السائق: كم طلب في نفس الوقت، ومن نفس المتجر ولا من أي متجر، ومناطق العمل.
 * نفس الإجراء في «السائقين» و«المستخدمين».
 */
class DriverCapacity
{
    private static function fields(bool $withZones = true): array
    {
        $fields = [
            Select::make('multi_order_mode')
                ->label('وضع تعدد الطلبات')
                ->options(DriverProfile::MODES)
                ->required()
                ->default('single')
                ->live()
                ->helperText('«من نفس المتجر فقط» = ياخذ عدة طلبات بس لو كلها من مطعم واحد (مشوار واحد).'),

            TextInput::make('max_active_orders')
                ->label(fn ($get) => $get('multi_order_mode') === 'same_store'
                    ? 'أقصى عدد طلبات من المتجر الواحد في نفس الوقت'
                    : 'أقصى عدد طلبات في نفس الوقت')
                ->numeric()->required()->minValue(1)->maxValue(20)->default(1)
                ->visible(fn ($get) => $get('multi_order_mode') !== 'single'),
        ];

        if ($withZones) {
            $fields[] = CheckboxList::make('zone_ids')
                ->label('مناطق العمل')
                ->options(fn () => DeliveryZone::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                ->columns(2)
                ->bulkToggleable()
                ->helperText('لو ما اخترت ولا وحدة، بتوصله طلبات كل المناطق.');
        }

        return $fields;
    }

    private static function values(array $data): array
    {
        $mode = $data['multi_order_mode'];

        return [
            'multi_order_mode'  => $mode,
            // «طلب واحد» = السعة 1 دائماً
            'max_active_orders' => $mode === 'single' ? 1 : max(1, (int) ($data['max_active_orders'] ?? 1)),
        ];
    }

    public static function action(): Action
    {
        return Action::make('driverSettings')
            ->authorize(fn () => \App\Support\Perm::can('users.manage'))
            ->label('إعدادات الطلبات والمناطق')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('info')
            ->visible(fn (User $record) => $record->hasRole(UserRole::Driver) && $record->driverProfile)
            ->fillForm(fn (User $record) => [
                'zone_ids'          => $record->driverProfile->zones()->pluck('delivery_zones.id')->all(),
                'max_active_orders' => $record->driverProfile->max_active_orders,
                'multi_order_mode'  => $record->driverProfile->multi_order_mode,
            ])
            ->schema(self::fields())
            ->action(function (User $record, array $data) {
                $record->driverProfile->update(self::values($data));
                $record->driverProfile->zones()->sync($data['zone_ids'] ?? []);

                Notification::make()->title('تم حفظ إعدادات السائق')->success()->send();
            });
    }

    public static function bulkAction(): BulkAction
    {
        return BulkAction::make('applyCapacity')
            ->authorize(fn () => \App\Support\Perm::can('users.manage'))
            ->label('ضبط سعة الطلبات')
            ->icon('heroicon-o-adjustments-horizontal')
            ->color('info')
            ->modalDescription('يطبّق نفس الإعدادات على كل السائقين المحددين.')
            ->schema(self::fields(false))
            ->action(function (Collection $records, array $data) {
                $count = 0;
                foreach ($records as $record) {
                    if ($record->driverProfile) {
                        $record->driverProfile->update(self::values($data));
                        $count++;
                    }
                }

                Notification::make()->title("تم تحديث {$count} سائق")->success()->send();
            });
    }
}
