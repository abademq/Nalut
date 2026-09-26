<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\ActivityLog;
use App\Support\Perm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** سجل نشاط المنصة: كل عملية من التطبيقات ولوحة التحكم والنظام */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 95;

    protected static ?string $slug = 'activity';

    public const TYPES = [
        'api'   => 'عمليات من التطبيقات',
        'app'   => 'أحداث داخل التطبيقات (تصفح، سلة...)',
        'model' => 'تغييرات في البيانات',
        'admin' => 'دخول وخروج اللوحة',
    ];

    public static function canViewAny(): bool
    {
        return Perm::can('logs.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return 'سجل النشاط';
    }

    public static function getModelLabel(): string
    {
        return 'نشاط';
    }

    public static function getPluralModelLabel(): string
    {
        return 'سجل النشاط';
    }

    /** رابط السجل مفلتر (من صفحة مستخدم أو طلب) */
    public static function filteredUrl(array $filters): string
    {
        return static::getUrl('index', ['filters' => $filters]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('30s')
            ->modifyQueryUsing(fn ($query) => $query->with(['user', 'order']))
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('d/m/Y H:i:s')->sortable()
                    ->description(fn (ActivityLog $r) => $r->created_at?->diffForHumans()),
                TextColumn::make('app')->label('من')->badge()
                    ->formatStateUsing(fn ($state) => ActivityLog::APPS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'customer' => 'info', 'store' => 'warning', 'driver' => 'success', 'admin' => 'danger', default => 'gray',
                    }),
                TextColumn::make('user.name')->label('الحساب')->placeholder('—')
                    ->description(fn (ActivityLog $r) => $r->user?->phone)
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('user',
                        fn ($u) => $u->where('name', 'like', "%$search%")->orWhere('phone', 'like', "%$search%"))),
                TextColumn::make('description')->label('العملية')->wrap()->searchable(),
                TextColumn::make('order.code')->label('الطلب')->placeholder('—')
                    ->url(fn (ActivityLog $r) => $r->order ? OrderResource::getUrl('view', ['record' => $r->order]) : null)
                    ->searchable(query: fn ($query, string $search) => $query->whereHas('order',
                        fn ($o) => $o->where('code', 'like', "%$search%"))),
                TextColumn::make('status')->label('النتيجة')->badge()->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state >= 400 ? "فشل ($state)" : 'تم')
                    ->color(fn ($state) => $state >= 400 ? 'danger' : 'success'),
                TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('device')->label('الجهاز')->limit(40)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('app')->label('من')->options(ActivityLog::APPS),
                SelectFilter::make('type')->label('النوع')->options(self::TYPES)
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'api'   => $query->where('action', 'like', 'api.%'),
                        'app'   => $query->where('action', 'like', 'app.%'),
                        'model' => $query->where('action', 'like', 'model.%'),
                        'admin' => $query->where('action', 'like', 'admin.%'),
                        default => $query,
                    }),
                SelectFilter::make('user_id')->label('الحساب')->relationship('user', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->name} — {$record->phone}")
                    ->searchable(['name', 'phone'])->preload(false),
                SelectFilter::make('store_id')->label('المتجر')->relationship('store', 'name')->searchable(),
                SelectFilter::make('order_id')->label('رقم الطلب')->relationship('order', 'code')->searchable(),
                SelectFilter::make('result')->label('النتيجة')->options(['ok' => 'تمت', 'failed' => 'فشلت'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'ok'     => $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<', 400)),
                        'failed' => $query->where('status', '>=', 400),
                        default  => $query,
                    }),
                Filter::make('period')->label('الفترة')
                    ->schema([
                        DateTimePicker::make('from')->label('من'),
                        DateTimePicker::make('until')->label('إلى'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
                        ->when($data['until'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))),
                Filter::make('action')->label('نوع العملية (كود)')
                    ->schema([TextInput::make('action')->label('نوع العملية (مثلاً app.cart.add)')])
                    ->query(fn ($query, array $data) => $query->when($data['action'] ?? null,
                        fn ($q, $v) => $q->where('action', 'like', "%$v%"))),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                Action::make('details')->label('التفاصيل')->icon('heroicon-o-eye')
                    ->modalHeading(fn (ActivityLog $r) => $r->description)
                    ->modalContent(fn (ActivityLog $r) => view('filament.activity-details', ['log' => $r]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق')
                    ->slideOver(),
            ])
            ->recordAction('details');
    }

    public static function getPages(): array
    {
        return ['index' => ListActivityLogs::route('/')];
    }
}
