<?php

namespace App\Filament\Pages;

use App\Support\Perm;
use App\Support\ReceiptLayout;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * مصمم الواصل التفاعلي: ترتيب القطع بالسحب، وتعديل كل قطعة (حجم، محاذاة، هوامش، إطار...)
 * مع معاينة حيّة بعرض ورق الطابعة.
 */
class ReceiptDesigner extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.receipt-designer';

    /** @var array<string, array> */
    public array $layouts = [];

    public static function canAccess(): bool
    {
        return Perm::can('settings.manage');
    }

    public static function getNavigationLabel(): string
    {
        return 'مصمم الواصل';
    }

    public function getTitle(): string
    {
        return 'مصمم الواصل';
    }

    public function getMaxContentWidth(): ?string
    {
        return 'full';
    }

    public function mount(): void
    {
        $this->layouts = ReceiptLayout::all();
    }

    /** يرجّع التصميم بعد التنظيف — المحرر يعتمده */
    public function saveLayout(string $copy, array $layout): array
    {
        abort_unless(Perm::can('settings.manage') && isset(ReceiptLayout::COPIES[$copy]), 403);

        $clean = ReceiptLayout::save($copy, $layout);
        $this->layouts[$copy] = $clean;

        Notification::make()
            ->title('تم حفظ '.ReceiptLayout::COPIES[$copy])
            ->body('الواصلات الجاية تطلع بالشكل الجديد (التطبيق ياخذه أول ما يتحدّث).')
            ->success()->send();

        return $clean;
    }

    public function resetLayout(string $copy): array
    {
        abort_unless(Perm::can('settings.manage') && isset(ReceiptLayout::COPIES[$copy]), 403);

        $this->layouts[$copy] = ReceiptLayout::reset($copy);

        Notification::make()->title('رجع التصميم الافتراضي')->success()->send();

        return $this->layouts[$copy];
    }

    /** بيانات ثابتة للمحرر: أنواع القطع، المتغيرات، الشعار */
    public function editorMeta(): array
    {
        return [
            'types'  => collect(ReceiptLayout::TYPES)->map(fn ($t, $k) => $t + [
                'default_label' => ReceiptLayout::defaultLabel($k),
            ])->all(),
            'vars'   => ReceiptLayout::VARS,
            'copies' => ReceiptLayout::COPIES,
            'logo'   => BrandingSettings::logoUrl(),
        ];
    }
}
