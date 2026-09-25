<?php

namespace App\Filament\Resources\FailureReasons;

use App\Filament\Resources\FailureReasons\Pages\CreateFailureReason;
use App\Filament\Resources\FailureReasons\Pages\EditFailureReason;
use App\Filament\Resources\FailureReasons\Pages\ListFailureReasons;
use App\Models\FailureReason;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** أسباب تعذّر التسليم اللي يختار منها السائق */
class FailureReasonResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'settings.manage';

    public const PERM_MANAGE = 'settings.manage';

    protected static ?string $model = FailureReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return 'سبب تعذّر تسليم';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أسباب تعذّر التسليم';
    }

    public static function getNavigationLabel(): string
    {
        return 'أسباب تعذّر التسليم';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->label('السبب')->required()->maxLength(80)
                ->helperText('يظهر للسائق كما هو')->columnSpanFull(),
            Toggle::make('hold_for_review')->label('يعلّق الطلب للمراجعة')
                ->helperText('مفعّل: الطلب يضل «قيد مراجعة الإدارة» لين تقرر. مطفي: الطلب يفشل مباشرة.')
                ->default(true),
            Toggle::make('open_support')->label('يفتح محادثة الدعم الفني')
                ->helperText('يفتح واتساب الدعم تلقائياً برسالة فيها تفاصيل البلاغ. الرقم من «عن التطبيق».')
                ->default(true),
            TextInput::make('sort')->label('الترتيب')->numeric()->default(0),
            Toggle::make('is_active')->label('مفعّل')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('label')->label('السبب')->weight('bold'),
                IconColumn::make('hold_for_review')->label('مراجعة')->boolean(),
                IconColumn::make('open_support')->label('دعم فني')->boolean(),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->recordActions([EditAction::make()->label('تعديل')]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListFailureReasons::route('/'),
            'create' => CreateFailureReason::route('/create'),
            'edit'   => EditFailureReason::route('/{record}/edit'),
        ];
    }
}
