<?php

namespace App\Filament\Pages;

use App\Filament\Resources\NotificationSettings\NotificationSettingResource;
use App\Support\Activity;
use App\Support\AdminAlertTypes;
use App\Support\Perm;
use App\Support\Sounds;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** «إعدادات الإشعارات ← تنبيهات لوحة التحكم»: كل نوع يظهر؟ بصوت؟ أي نغمة؟ لتطبيق الإدارة؟ */
class AdminAlertSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Perm::can('settings.manage');
    }

    public function getTitle(): string
    {
        return 'إعدادات الإشعارات — تنبيهات لوحة التحكم';
    }

    public function getSubheading(): ?string
    {
        return 'لكل نوع: يظهر في جرس اللوحة؟ معاه صوت؟ أي نغمة؟ ويوصل لتطبيق «ازانكس إدارة» على الهاتف؟';
    }

    public function mount(): void
    {
        $values = [];
        foreach (array_keys(AdminAlertTypes::TYPES) as $type) {
            $values[$type] = AdminAlertTypes::config($type);
        }
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (AdminAlertTypes::TYPES as $type => [$label, $help]) {
            $sections[] = Section::make($label)
                ->description($help)
                ->columns(4)
                ->compact()
                ->schema([
                    Toggle::make("$type.enabled")->label('يظهر في اللوحة')->live(),
                    Toggle::make("$type.sound")->label('معاه تنبيه صوتي')
                        ->disabled(fn ($get) => ! $get("$type.enabled") && ! $get("$type.push"))->live(),
                    Select::make("$type.tone")->label('النغمة (في اللوحة)')
                        ->options(Sounds::TONES)->native(false)->required()
                        ->disabled(fn ($get) => ! $get("$type.sound")),
                    Toggle::make("$type.push")->label('تطبيق الإدارة (الهاتف)')->live(),
                ]);
        }

        return $schema->statePath('data')->components($sections);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('roles')
                ->label('إشعارات الزبون والمتجر والسائق')
                ->icon('heroicon-o-arrow-uturn-right')
                ->color('gray')
                ->url(NotificationSettingResource::getUrl('index')),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        foreach (array_keys(AdminAlertTypes::TYPES) as $type) {
            // الحقول المقفولة ما ترجعش في الحالة — نكمّلوها من القيمة الحالية
            AdminAlertTypes::save($type, ($state[$type] ?? []) + AdminAlertTypes::config($type));
        }
        Activity::record('settings.admin_alerts', 'تعديل تنبيهات لوحة التحكم');
        Notification::make()->title('تم الحفظ')->success()->send();
    }
}
