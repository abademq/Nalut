<?php

namespace App\Filament\Resources\TicketCategories;

use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Resources\TicketCategories\Pages\ManageTicketCategories;
use App\Models\Ticket;
use App\Models\TicketCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use UnitEnum;

/** أنواع المشاكل اللي يختار منها الزبون/السائق/المتجر لما يفتح تذكرة */
class TicketCategoryResource extends Resource
{
    use GuardedByPermission;

    public const PERM_VIEW = 'support.manage';

    public const PERM_MANAGE = 'support.manage';

    protected static ?string $model = TicketCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'الطلبات';

    protected static ?int $navigationSort = 6;

    public static function getModelLabel(): string
    {
        return 'نوع مشكلة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'أنواع مشاكل الدعم';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('app')->label('يطلع في')
                ->options(['customer' => 'تطبيق الزبون وموقع الطلب', 'driver' => 'تطبيق السائق', 'store' => 'تطبيق المتجر'])
                ->required()->native(false)
                ->disabledOn('edit'),
            TextInput::make('label')->label('الاسم اللي يشوفه')->required()->maxLength(80)
                ->placeholder('مثلاً: تأخير في التوصيل')
                ->helperText('تغيير الاسم ما يأثرش على التذاكر القديمة — تطلع بالاسم الجديد.'),
            Toggle::make('is_active')->label('مفعّل')->default(true)
                ->helperText('الموقوف ما يطلعش في التطبيق، والتذاكر القديمة بيه تقعد عادي.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->defaultGroup('app')
            ->groups([
                Group::make('app')->label('التطبيق')
                    ->getTitleFromRecordUsing(fn (TicketCategory $r) => Ticket::APPS[$r->app] ?? $r->app)
                    ->collapsible(),
            ])
            ->columns([
                TextColumn::make('label')->label('النوع')->weight('bold'),
                TextColumn::make('tickets')->label('عدد التذاكر')->badge()
                    ->state(fn (TicketCategory $r) => $r->ticketsCount()),
                ToggleColumn::make('is_active')->label('مفعّل'),
            ])
            ->filters([
                SelectFilter::make('app')->label('التطبيق')->options(Ticket::APPS),
            ])
            ->recordActions([
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف')
                    // عليه تذاكر: ما ينحذفش — يتوقف بس
                    ->before(function (TicketCategory $record, DeleteAction $action) {
                        if ($record->ticketsCount() > 0) {
                            Notification::make()->title('ما ينحذفش')
                                ->body('فيه تذاكر بالنوع هذا — طفّيه بدل الحذف.')->warning()->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTicketCategories::route('/')];
    }
}
