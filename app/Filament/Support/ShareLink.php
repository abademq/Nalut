<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

/** زر «رابط المشاركة»: يعرض الرابط جاهز للنسخ — يفتح التطبيق على المتجر أو الصنف */
class ShareLink
{
    public static function make(callable $url): Action
    {
        return Action::make('shareLink')
            ->label('رابط المشاركة')
            ->icon('heroicon-o-link')
            ->color('gray')
            ->modalHeading('رابط يفتح التطبيق مباشرة')
            ->modalDescription('حطّه في رسالة واتساب أو منشور أو حملة. لو التطبيق مثبّت يفتح على المكان هذا، ولو لا يوديه لصفحة فيها زر التحميل.')
            ->fillForm(fn ($record) => ['url' => $url($record)])
            ->schema([
                TextInput::make('url')->label('الرابط')->readOnly()->copyable(copyMessage: 'تم النسخ'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('إغلاق');
    }
}
