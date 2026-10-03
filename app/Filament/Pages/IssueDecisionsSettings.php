<?php

namespace App\Filament\Pages;

use App\Models\OrderIssue;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\IssueDecisions;
use App\Support\Options;
use App\Support\Perm;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * قرارات الرد على بلاغات السائقين + شن يصير بعد انتهاء مؤقت التسليم.
 */
class IssueDecisionsSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    private const HANDOVER = [
        'delivery.handover_wait_minutes', 'handover.expired_action', 'handover.driver_message',
        'handover.customer_message', 'handover.notify_on_expiry', 'delivery.leave_at_door_cash',
    ];

    public static function canAccess(): bool
    {
        return Perm::can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return 'قرارات البلاغات والتسليم';
    }

    public function getTitle(): string
    {
        return 'قرارات البلاغات والتسليم';
    }

    private static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function mount(): void
    {
        $values = ['decisions' => IssueDecisions::all()];
        foreach (self::HANDOVER as $key) {
            $values['handover'][self::field($key)] = Options::get($key);
        }
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $defs = Options::definitions();

        $handover = [];
        foreach (self::HANDOVER as $key) {
            $def = $defs[$key];
            $name = 'handover.'.self::field($key);
            $field = match (true) {
                $def['type'] === 'bool' => Toggle::make($name),
                isset($def['choices']) => Select::make($name)->options($def['choices'])->native(false)->required(),
                $def['type'] === 'int' => TextInput::make($name)->numeric()->required()->minValue($def['min'] ?? null)->maxValue($def['max'] ?? null),
                default => Textarea::make($name)->rows(2)->maxLength(300)->columnSpanFull(),
            };
            $handover[] = $field->label($def['label'])->helperText($def['help'] ?? null);
        }

        $sections = [
            Section::make('بعد انتهاء مؤقت التسليم')
                ->description('لما السائق يضغط «وصلت عند الزبون» يبدا مؤقت للزبون. هني تحدد مدته، وشن يدير السائق لو ما استلمش، والرسائل اللي تطلع لكل واحد.')
                ->columns(2)
                ->schema($handover),
        ];

        foreach (OrderIssue::RESOLUTIONS as $key => $default) {
            $p = "decisions.$key";
            $sections[] = Section::make("قرار البلاغ: {$default}")
                ->description(match ($key) {
                    'continue' => 'البلاغ يتقفل والسائق يكمّل نفس الطلب.',
                    'reassign' => 'الطلب يرجع «جاهز» ويوصل للسائقين المتاحين، والسائق الحالي ينسحب منه.',
                    'failed' => 'الطلب يتقفل «فشل التسليم» (يرجع المخزون والمحفظة حسب الإعدادات).',
                    'cancelled' => 'الطلب يتلغى بالكامل.',
                    default => null,
                })
                ->columns(2)
                ->collapsible()
                ->schema([
                    Toggle::make("$p.enabled")->label('يظهر في قائمة القرارات'),
                    TextInput::make("$p.label")->label('الاسم اللي يطلع في القائمة')->placeholder($default)->maxLength(60),
                    Textarea::make("$p.driver_message")->label('الرسالة اللي توصل للسائق')->rows(2)->maxLength(300)
                        ->placeholder(in_array($key, ['continue', 'reassign'], true) ? 'فاضي = النص الافتراضي من «النصوص»' : 'فاضي = بدون رسالة إضافية')
                        ->helperText('تقدر تستعمل {code} = رقم الطلب، {note} = ملاحظة الإدارة.'),
                    Textarea::make("$p.customer_message")->label('الرسالة اللي توصل للزبون (اختياري)')->rows(2)->maxLength(300)
                        ->placeholder('فاضي = بدون رسالة إضافية (إشعار تغيير الحالة يوصل عادي)'),
                ]);
        }

        return $schema->statePath('data')->components($sections);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $enabled = collect($state['decisions'] ?? [])->filter(fn ($d) => ! empty($d['enabled']));
        if ($enabled->isEmpty()) {
            throw ValidationException::withMessages(['data.decisions.continue.enabled' => 'لازم قرار واحد على الأقل يكون مفعّل.']);
        }

        foreach (array_keys(OrderIssue::RESOLUTIONS) as $key) {
            IssueDecisions::save($key, $state['decisions'][$key] ?? []);
        }

        $defs = Options::definitions();
        foreach (self::HANDOVER as $key) {
            $value = $state['handover'][self::field($key)] ?? null;
            Setting::put("opt.$key", match ($defs[$key]['type']) {
                'bool' => $value ? '1' : '0',
                default => trim((string) $value),
            });
        }

        Activity::record('settings.issue_decisions', 'تعديل قرارات البلاغات وإجراء ما بعد مؤقت التسليم');
        Notification::make()->title('تم الحفظ')->success()->send();
    }
}
