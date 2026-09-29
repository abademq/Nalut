<?php

namespace App\Filament\Resources\LegalDocuments;

use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Resources\LegalDocuments\Pages\EditLegalDocument;
use App\Filament\Resources\LegalDocuments\Pages\ListLegalDocuments;
use App\Models\LegalDocument;
use App\Models\UserConsent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** سياسة الخصوصية والشروط واتفاقيات المندوبين والمتاجر — تطلع في التطبيقات وفي روابط عامة */
class LegalDocumentResource extends Resource
{
    use GuardedByPermission;

    public const PERM_VIEW = 'settings.manage';

    public const PERM_MANAGE = 'settings.manage';

    protected static ?string $model = LegalDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return 'وثيقة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الشروط والخصوصية';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            TextInput::make('title')->label('العنوان')->required()->maxLength(120),
            Textarea::make('body')->label('النص')->required()->rows(28)
                ->extraInputAttributes(['style' => 'font-family: inherit; line-height: 1.8'])
                ->helperText('تنسيق بسيط: «## » عنوان · «- » نقطة · **كلام** عريض · «> » مقدمة. '
                    .'وتتعبّى لحالها: {app} اسم التطبيق · {company} اسم الشركة · {phone} · {email} · {date} تاريخ آخر تحديث (من «عن التطبيق»).'),
            Toggle::make('require_reconsent')->label('تغيير جوهري: اطلب من الكل الموافقة من جديد')
                ->helperText('يرفع رقم النسخة، وأول ما يفتحو التطبيق يطلعلهم طلب الموافقة. للتصحيحات البسيطة خليه مطفي.')
                ->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('title')->label('الوثيقة')->weight('bold')
                    ->description(fn (LegalDocument $r) => match ($r->key) {
                        'privacy' => 'الكل (زبون، مندوب، متجر)',
                        'terms_customer' => 'تطبيق الزبون وموقع الطلب',
                        'terms_driver' => 'تطبيق المندوب',
                        'terms_store' => 'تطبيق المتجر',
                        default => null,
                    }),
                TextColumn::make('version')->label('النسخة')->badge(),
                TextColumn::make('accepted')->label('وافقو على النسخة الحالية')->badge()->color('success')
                    ->state(fn (LegalDocument $r) => UserConsent::where('document', $r->key)->where('version', $r->version)->distinct('user_id')->count('user_id')),
                TextColumn::make('updated_at')->label('آخر تعديل')->since(),
            ])
            ->recordActions([
                Action::make('open')->label('الرابط العام')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                    ->url(fn (LegalDocument $r) => route('legal.show', str_replace('_', '-', $r->key)))->openUrlInNewTab(),
                EditAction::make()->label('تعديل'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLegalDocuments::route('/'),
            'edit' => EditLegalDocument::route('/{record}/edit'),
        ];
    }
}
