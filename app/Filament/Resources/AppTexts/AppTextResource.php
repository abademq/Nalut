<?php

namespace App\Filament\Resources\AppTexts;

use App\Filament\Resources\AppTexts\Pages\ListAppTexts;
use App\Models\AppText;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * كل نصوص التطبيقات والإشعارات في مكان واحد.
 * الإدارة تكتب نص بديل — والفاضي يرجّع الأصل.
 */
class AppTextResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'settings.manage';

    public const PERM_MANAGE = 'settings.manage';

    protected static ?string $model = AppText::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return 'نص';
    }

    public static function getPluralModelLabel(): string
    {
        return 'النصوص';
    }

    public static function getNavigationLabel(): string
    {
        return 'النصوص والإشعارات';
    }

    public static function canCreate(): bool
    {
        return false; // النصوص تجي من الكود عبر texts:sync
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('default')->label('النص الأصلي')->columnSpanFull(),
            TextEntry::make('vars')->label('المتغيرات المتاحة')->columnSpanFull()
                ->visible(fn (?AppText $record) => filled($record?->vars)),
            Textarea::make('value')->label('النص الجديد')->rows(3)->columnSpanFull()
                ->helperText('خليه فاضي باش يرجع النص الأصلي. انسخ المتغيرات اللي بين {} كما هي.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->orderBy('group')->orderBy('id'))
            ->columns([
                TextColumn::make('group')->label('المكان')->badge()->color('gray')
                    ->searchable(),
                TextColumn::make('default')->label('النص الأصلي')->wrap()->searchable()
                    ->description(fn (AppText $r) => $r->vars),
                TextColumn::make('value')->label('النص الجديد')->wrap()->searchable()
                    ->placeholder('— الأصل —')->color('success')->weight('bold'),
            ])
            ->filters([
                SelectFilter::make('group')->label('المكان')
                    ->options(fn () => AppText::query()->distinct()->orderBy('group')->pluck('group', 'group')->all()),
                TernaryFilter::make('customized')->label('المعدّلة')
                    ->placeholder('الكل')->trueLabel('المعدّلة فقط')->falseLabel('غير المعدّلة')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('value'),
                        false: fn (Builder $query) => $query->whereNull('value'),
                    ),
                TernaryFilter::make('is_used')->label('مستعمل في التطبيق')
                    ->default(true)->placeholder('الكل')->trueLabel('المستعملة')->falseLabel('القديمة'),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل')->modalHeading('تعديل النص'),
                Action::make('reset')->label('رجّع الأصل')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (AppText $r) => $r->isCustomized())
                    ->authorize(fn () => static::canEdit(new AppText))
                    ->requiresConfirmation()
                    ->action(fn (AppText $r) => $r->update(['value' => null])),
            ])
            ->recordUrl(null)
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppTexts::route('/'),
        ];
    }
}
