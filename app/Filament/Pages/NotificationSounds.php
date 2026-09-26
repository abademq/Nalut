<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Support\Sounds;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** صوت الإشعارات: لوحة التحكم، وتطبيق الزبون والسائق والمتجر */
class NotificationSounds extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSpeakerWave;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.app-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return \App\Support\Perm::can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return 'أصوات الإشعارات';
    }

    public function getTitle(): string
    {
        return 'أصوات الإشعارات';
    }

    public function mount(): void
    {
        $fill = [];
        foreach (array_keys(Sounds::TARGETS) as $t) {
            $fill[$t] = ['tone' => Sounds::tone($t), 'file' => Sounds::customPath($t)];
        }
        $this->form->fill($fill);
    }

    private static function previews(): HtmlString
    {
        $items = collect(Sounds::TONES)->except('default')->map(fn ($label, $key) => sprintf(
            '<div style="display:flex;align-items:center;gap:8px;margin:4px 0"><span style="min-width:120px">%s</span>'
            .'<audio controls preload="none" style="height:32px" src="%s"></audio></div>',
            e($label), e(asset("sounds/tone_$key.wav"))
        ))->implode('');

        return new HtmlString('<div>'.$items.'</div>');
    }

    public function form(Schema $schema): Schema
    {
        $sections = [
            Section::make('استمع للنغمات')
                ->description('النغمات هذي موجودة داخل التطبيقات، فتشتغل حتى والتطبيق مقفول.')
                ->collapsible()
                ->schema([Html::make(self::previews())]),
        ];

        $notes = [
            'admin'    => 'صوت التنبيهات الفورية في لوحة التحكم (الطلبات الجديدة، المشاكل...).',
            'customer' => 'إشعارات حالة الطلب والعروض عند الزبون.',
            'driver'   => 'إشعارات الطلبات المتاحة والمسندة، وصوت «طلب جديد» داخل التطبيق.',
            'store'    => 'صوت «طلب جديد» داخل تطبيق المتجر.',
        ];

        foreach (Sounds::TARGETS as $t => $label) {
            $sections[] = Section::make($label)
                ->description($notes[$t])
                ->columns(2)
                ->schema([
                    Select::make("$t.tone")->label('النغمة')->native(false)->required()
                        ->options(Sounds::TONES),
                    FileUpload::make("$t.file")->label('أو ارفع صوت خاص (اختياري)')
                        ->disk('public')->directory('sounds')
                        ->acceptedFileTypes(['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/ogg'])
                        ->maxSize(1024)
                        ->helperText($t === 'admin'
                            ? 'MP3 أو WAV قصير (أقل من 1 ميغا).'
                            : 'يشتغل والتطبيق مفتوح. والتطبيق في الخلفية يشتغل صوت «النغمة» — أندرويد ما يسمحش بصوت من ملف خارجي.'),
                ]);
        }

        return $schema->statePath('data')->components($sections);
    }

    public function save(): void
    {
        $s = $this->form->getState();

        foreach (array_keys(Sounds::TARGETS) as $t) {
            Setting::put("sound.$t", (string) ($s[$t]['tone'] ?? 'default'));
            Setting::put("sound.$t.file", (string) ($s[$t]['file'] ?? ''));
        }

        Notification::make()->title('تم الحفظ — التطبيقات تاخذ الصوت الجديد أول ما تنفتح')->success()->send();
    }
}
