<?php

namespace App\Filament\Resources\Messaging;

use App\Models\MessageTemplate;
use App\Models\ReportSubscription;
use App\Models\Store;
use App\Services\Reports\StoreReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** تقارير تنبعت على واتساب/SMS للمتاجر أو لأي رقم — مرة وحدة أو دورية */
class ReportSubscriptionResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'messages.manage';

    public const PERM_MANAGE = 'messages.manage';

    protected static ?string $model = ReportSubscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'الرسائل';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'report-subscriptions';

    public static function getModelLabel(): string
    {
        return 'تقرير دوري';
    }

    public static function getPluralModelLabel(): string
    {
        return 'التقارير الدورية';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('اسم التقرير')->required()->maxLength(80)
                ->placeholder('ملخص يومي — مطعم نالوت'),
            Select::make('store_id')->label('المتجر')->native(false)->searchable()
                ->options(fn () => Store::orderBy('name')->pluck('name', 'id'))
                ->placeholder('كل المنصة (ملخص الإدارة)')->live(),
            Select::make('recipient')->label('يوصل لـ')->native(false)->required()->default('store')->live()
                ->options(['store' => 'رقم المتجر', 'custom' => 'رقم محدد']),
            TextInput::make('phone')->label('الرقم')->tel()->placeholder('09XXXXXXXX')
                ->required(fn ($get) => $get('recipient') === 'custom' || ! $get('store_id'))
                ->visible(fn ($get) => $get('recipient') === 'custom' || ! $get('store_id')),
            Select::make('message_template_id')->label('القالب')->required()->native(false)
                ->options(fn () => MessageTemplate::where('is_active', true)->whereIn('purpose', ['report', 'general'])
                    ->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->name} — ".(MessageTemplate::CHANNELS[$t->channel] ?? $t->channel)]))
                ->helperText('محتوى التقرير = متغيرات القالب ({orders} {sales} {balance} ...) — تتحدد في «قوالب الرسائل».'),
            Select::make('period')->label('الفترة اللي يغطيها')->options(StoreReport::PERIODS)->required()->default('today')->native(false),
            Select::make('frequency')->label('الإرسال')->options(ReportSubscription::FREQUENCIES)->required()->default('daily')->native(false)->live(),
            DateTimePicker::make('send_at')->label('وقت الإرسال')->seconds(false)
                ->required(fn ($get) => $get('frequency') === 'once')
                ->visible(fn ($get) => $get('frequency') === 'once'),
            TimePicker::make('send_time')->label('الساعة (توقيت ليبيا)')->seconds(false)->timezone('UTC')->default('23:00')
                ->visible(fn ($get) => $get('frequency') !== 'once'),
            Select::make('weekday')->label('يوم الأسبوع')->options(ReportSubscription::WEEKDAYS)->default(6)->native(false)
                ->visible(fn ($get) => $get('frequency') === 'weekly'),
            TextInput::make('month_day')->label('يوم الشهر')->numeric()->minValue(1)->maxValue(28)->default(1)
                ->visible(fn ($get) => $get('frequency') === 'monthly'),
            Toggle::make('is_active')->label('مفعّل')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('التقرير')->weight('bold')->searchable()
                    ->description(fn (ReportSubscription $r) => $r->store?->name ?? 'كل المنصة'),
                TextColumn::make('frequency')->label('الإرسال')->badge()
                    ->formatStateUsing(fn (ReportSubscription $r) => (ReportSubscription::FREQUENCIES[$r->frequency] ?? $r->frequency)
                        .($r->frequency !== 'once' ? ' '.$r->send_time : '')),
                TextColumn::make('period')->label('الفترة')->formatStateUsing(fn ($state) => StoreReport::PERIODS[$state] ?? $state),
                TextColumn::make('template.name')->label('القالب'),
                TextColumn::make('next_run_at')->label('الإرسال الجاي')->dateTime('d/m H:i')->placeholder('—'),
                TextColumn::make('last_sent_at')->label('آخر إرسال')->dateTime('d/m H:i')->placeholder('—')
                    ->description(fn (ReportSubscription $r) => match ($r->last_status) {
                        'sent' => 'وصل', 'failed' => 'فشل', 'skipped' => 'القناة مش مضبوطة', default => null,
                    }),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->recordActions([
                Action::make('preview')->label('معاينة')->icon(Heroicon::OutlinedEye)->color('gray')
                    ->modalHeading('معاينة التقرير بأرقام توّا')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (ReportSubscription $r) => new \Illuminate\Support\HtmlString(
                        '<div dir="rtl" style="white-space:pre-wrap;line-height:1.9">'.e($r->preview()).'</div>'
                    )),
                Action::make('send')->label('ابعت توّا')->icon(Heroicon::OutlinedPaperAirplane)->color('success')
                    ->authorize(fn () => static::canCreate())
                    ->requiresConfirmation()
                    ->action(function (ReportSubscription $r) {
                        $log = $r->sendNow();
                        Notification::make()
                            ->title(match ($log->status) {
                                'sent' => 'انبعت ✅', 'skipped' => 'ما انبعتش — القناة مش مضبوطة في .env', default => 'فشل',
                            })
                            ->body($log->error)
                            ->color($log->status === 'sent' ? 'success' : 'danger')->send();
                    }),
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ReportSubscriptionResource\ManageReports::route('/')];
    }
}
