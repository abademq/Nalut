<?php

namespace App\Filament\Resources\Messaging;

use App\Models\Campaign;
use App\Models\MessageTemplate;
use App\Models\Store;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** حملات تسويقية لأرقام الزبائن */
class CampaignResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'messages.manage';

    public const PERM_MANAGE = 'messages.manage';

    protected static ?string $model = Campaign::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'الرسائل';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'campaigns';

    public static function getModelLabel(): string
    {
        return 'حملة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الحملات التسويقية';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('اسم الحملة')->required()->maxLength(120),
            Select::make('channel')->label('نوع الحملة')->options(Campaign::CHANNELS)->required()->default('template')->native(false)->live(),
            Select::make('target_role')->label('للتطبيق')->options(Campaign::ROLES)->default('customer')->native(false)->live()
                ->visible(fn ($get) => $get('channel') === 'push'),
            TextInput::make('push_title')->label('عنوان الإشعار')->maxLength(120)
                ->required(fn ($get) => $get('channel') === 'push')
                ->visible(fn ($get) => $get('channel') === 'push')
                ->helperText('{name} = اسم المستلم'),
            Textarea::make('push_body')->label('نص الإشعار')->rows(3)->maxLength(500)
                ->required(fn ($get) => $get('channel') === 'push')
                ->visible(fn ($get) => $get('channel') === 'push'),
            TextInput::make('push_link')->label('يفتح على (رابط مشاركة — اختياري)')->maxLength(255)
                ->placeholder('https://api.dar-almaqam.com.ly/s/5')
                ->helperText('انسخه من 🔗 في المتاجر/المنتجات أو من «روابط التطبيق». يشتغل في تطبيق الزبون.')
                ->visible(fn ($get) => $get('channel') === 'push' && $get('target_role') === 'customer'),
            Select::make('message_template_id')->label('القالب')->native(false)
                ->required(fn ($get) => $get('channel') !== 'push')
                ->visible(fn ($get) => $get('channel') !== 'push')
                ->options(fn () => MessageTemplate::where('is_active', true)->whereIn('purpose', ['marketing', 'general'])
                    ->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->name} — ".(MessageTemplate::CHANNELS[$t->channel] ?? $t->channel)]))
                ->helperText('القوالب من «قوالب الرسائل». المتغيّر {name} = اسم الزبون.'),
            Select::make('audience')->label('لمنو؟')->required()->default('all')->native(false)->live()
                ->options(fn ($get) => $get('channel') === 'push'
                    ? collect(Campaign::AUDIENCES)->except('numbers')->all()
                    : Campaign::AUDIENCES)
                ->visible(fn ($get) => $get('channel') !== 'push' || $get('target_role') === 'customer'),
            TextInput::make('audience_params.days')->label('عدد الأيام')->numeric()->default(30)->minValue(1)
                ->visible(fn ($get) => in_array($get('audience'), ['active', 'inactive'], true)),
            Select::make('audience_params.store_id')->label('المتجر')->native(false)->searchable()
                ->options(fn () => Store::orderBy('name')->pluck('name', 'id'))
                ->visible(fn ($get) => $get('audience') === 'store'),
            Textarea::make('audience_params.numbers')->label('الأرقام')->rows(4)
                ->helperText('رقم في كل سطر أو مفصولة بفاصلة: 0912345678')
                ->visible(fn ($get) => $get('audience') === 'numbers')->columnSpanFull(),
            DateTimePicker::make('scheduled_at')->label('وقت الإرسال (فاضي = أول ما تضغط إرسال)')->seconds(false),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->columns([
                TextColumn::make('title')->label('الحملة')->weight('bold')->searchable(),
                TextColumn::make('template.name')->label('القالب / الإشعار')
                    ->state(fn (Campaign $r) => $r->channel === 'push' ? $r->push_title : $r->template?->name)
                    ->description(fn (Campaign $r) => $r->channel === 'push'
                        ? 'إشعار — '.(Campaign::ROLES[$r->target_role] ?? '')
                        : (MessageTemplate::CHANNELS[$r->template?->channel] ?? '')),
                TextColumn::make('audience')->label('الجمهور')->formatStateUsing(fn ($state) => Campaign::AUDIENCES[$state] ?? $state),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => Campaign::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'done' => 'success', 'sending', 'queued' => 'warning', 'failed' => 'danger', default => 'gray',
                    }),
                TextColumn::make('sent')->label('وصلت / الكل')
                    ->formatStateUsing(fn (Campaign $r) => "{$r->sent} / {$r->total}".($r->failed ? " · فشل {$r->failed}" : '')),
                TextColumn::make('scheduled_at')->label('الموعد')->dateTime('d/m/Y H:i')->placeholder('فوراً'),
            ])
            ->recordActions([
                Action::make('count')->label('كم رقم؟')->icon(Heroicon::OutlinedUsers)->color('gray')
                    ->action(fn (Campaign $r) => Notification::make()
                        ->title('الحملة بتوصل لـ '.$r->recipients()->count().' رقم')->info()->send()),
                Action::make('send')->label('إرسال')->icon(Heroicon::OutlinedPaperAirplane)->color('success')
                    ->visible(fn (Campaign $r) => in_array($r->status, ['draft', 'failed'], true))
                    ->authorize(fn () => static::canCreate())
                    ->requiresConfirmation()
                    ->modalHeading('تأكيد إرسال الحملة')
                    ->modalDescription(fn (Campaign $r) => 'بتوصل لـ '.$r->recipients()->count().' رقم'
                        .($r->scheduled_at ? ' في الموعد المحدد.' : ' خلال دقيقة.').' كل رسالة لها تكلفة عند المزوّد.')
                    ->action(function (Campaign $r) {
                        $r->update(['status' => 'queued', 'sent' => 0, 'failed' => 0]);
                        Notification::make()->title('الحملة في الطابور — تبدا خلال دقيقة')->success()->send();
                    }),
                Action::make('cancel')->label('إيقاف')->icon(Heroicon::OutlinedStop)->color('danger')
                    ->visible(fn (Campaign $r) => $r->status === 'queued')
                    ->authorize(fn () => static::canCreate())
                    ->action(fn (Campaign $r) => $r->update(['status' => 'draft'])),
                EditAction::make()->label('تعديل')->visible(fn (Campaign $r) => $r->status === 'draft'),
                DeleteAction::make()->label('حذف')->visible(fn (Campaign $r) => in_array($r->status, ['draft', 'done', 'failed'], true)),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => CampaignResource\ManageCampaigns::route('/')];
    }
}
