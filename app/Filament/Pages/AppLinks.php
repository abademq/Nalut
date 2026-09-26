<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** روابط تفتح التطبيق على شاشة معيّنة — للحملات والمنشورات */
class AppLinks extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = 'الرسائل';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.app-links';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return \App\Support\Perm::can('messages.manage');
    }

    public static function getNavigationLabel(): string
    {
        return 'روابط التطبيق';
    }

    public function getTitle(): string
    {
        return 'روابط تفتح التطبيق مباشرة';
    }

    public function mount(): void
    {
        $this->form->fill(collect(config('applinks.screens'))
            ->mapWithKeys(fn ($l, $k) => [$k => route('link.screen', $k)])->all());
    }

    public function form(Schema $schema): Schema
    {
        $verified = filled(config('applinks.android_sha256'));

        $fields = collect(config('applinks.screens'))->map(fn ($label, $key) => TextInput::make($key)
            ->label($label)->readOnly()->copyable(copyMessage: 'تم النسخ'))->values()->all();

        return $schema->statePath('data')->components([
            Section::make('روابط المتاجر والأصناف')
                ->description('من صفحة «المتاجر» أو «المنتجات» اضغط أيقونة الرابط 🔗 جنب أي متجر أو صنف.')
                ->schema([]),
            Section::make('روابط الشاشات')->columns(2)->schema($fields),
            Section::make('حالة الفتح المباشر')
                ->schema([
                    Text::make($verified
                        ? '✅ بصمة مفتاح التطبيق مضبوطة — الروابط تفتح التطبيق مباشرة بدون سؤال.'
                        : '⚠️ بصمة مفتاح التطبيق (APP_ANDROID_SHA256) مش مضبوطة في .env — الرابط يفتح صفحة فيها زر «افتح في التطبيق» بدل ما يفتح التطبيق مباشرة.'),
                    Text::make('اسم الحزمة: '.config('applinks.android_package')),
                ]),
        ]);
    }
}
