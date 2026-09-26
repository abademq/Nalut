<?php

namespace App\Filament\Resources\Messaging;

use App\Models\MessageTemplate;
use App\Services\Messaging\Messenger;
use App\Services\Reports\StoreReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * قوالب الرسائل — واتساب وSMS ما يسمحوش بنص حر للتسويق والتقارير،
 * فكل قالب لازم يكون معتمد عند المزوّد أول، وهنا نربطوه ونحدّدو متغيراته.
 */
class MessageTemplateResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'messages.manage';

    public const PERM_MANAGE = 'messages.manage';

    protected static ?string $model = MessageTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'الرسائل';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'message-templates';

    public static function getModelLabel(): string
    {
        return 'قالب رسالة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'قوالب الرسائل';
    }

    public static function form(Schema $schema): Schema
    {
        $vars = collect(StoreReport::VARS)->map(fn ($l, $k) => "<code>{$k}</code> {$l}")->implode(' · ');

        return $schema->components([
            TextInput::make('name')->label('اسم القالب (للإدارة)')->required()->maxLength(80),
            Select::make('purpose')->label('الاستعمال')->options(MessageTemplate::PURPOSES)->required()->default('marketing')->native(false),
            Select::make('channel')->label('القناة')->options(MessageTemplate::CHANNELS)->required()->default('whatsapp')->native(false)->live(),
            TextInput::make('provider_ref')->required()->maxLength(120)
                ->label(fn ($get) => $get('channel') === 'sms' ? 'رقم القالب في رسالة (sms_template_id)' : 'اسم القالب في واتساب (Template name)')
                ->helperText('نفس اللي في لوحة المزوّد بالضبط.'),
            TextInput::make('language')->label('لغة القالب')->default('ar')->maxLength(10)
                ->visible(fn ($get) => $get('channel') === 'whatsapp'),
            TagsInput::make('params')->label('قيم المتغيرات بالترتيب')
                ->helperText(new HtmlString('الأولى تعبّي {{1}} (أو $1 في رسالة)، الثانية {{2}}... ممكن تكتب نص ثابت أو متغيّر مثل <code>{name}</code>.<br>'
                    .'<b>الحملات:</b> <code>{name}</code> اسم الزبون.<br><b>التقارير:</b> '.$vars
                    .'<br><b>التنبيهات:</b> <code>{title}</code> <code>{body}</code>'))
                ->placeholder('اكتب واضغط Enter')
                ->reorderable()
                ->columnSpanFull(),
            Textarea::make('preview')->label('نص القالب كما هو معتمد (للمعاينة)')->rows(4)->columnSpanFull()
                ->helperText('انسخه من لوحة المزوّد — بـ {{1}} {{2}} أو $1 $2 في أماكن المتغيرات.'),
            Toggle::make('is_active')->label('مفعّل')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('القالب')->weight('bold')->searchable(),
                TextColumn::make('purpose')->label('الاستعمال')->badge()->formatStateUsing(fn ($state) => MessageTemplate::PURPOSES[$state] ?? $state),
                TextColumn::make('channel')->label('القناة')->badge()
                    ->color(fn ($state) => $state === 'whatsapp' ? 'success' : 'info')
                    ->formatStateUsing(fn ($state) => MessageTemplate::CHANNELS[$state] ?? $state),
                TextColumn::make('provider_ref')->label('عند المزوّد')->fontFamily('mono'),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->recordActions([
                Action::make('test')->label('تجربة')->icon(Heroicon::OutlinedPaperAirplane)
                    ->authorize(fn () => static::canCreate())
                    ->schema([
                        TextInput::make('phone')->label('ابعت لرقم')->tel()->required()->placeholder('09XXXXXXXX'),
                    ])
                    ->action(function (MessageTemplate $record, array $data) {
                        $vars = ['name' => 'تجربة', 'title' => 'رسالة تجربة', 'body' => 'من لوحة التحكم']
                            + StoreReport::build(null, 'today');
                        $log = app(Messenger::class)->send($record, $data['phone'], $vars, 'alert');

                        Notification::make()
                            ->title(match ($log->status) {
                                'sent'    => 'انبعتت ✅',
                                'skipped' => 'ما انبعتتش — القناة مش مضبوطة في .env',
                                default   => 'فشلت',
                            })
                            ->body($log->error)
                            ->color($log->status === 'sent' ? 'success' : 'danger')
                            ->send();
                    }),
                EditAction::make()->label('تعديل'),
                DeleteAction::make()->label('حذف'),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => MessageTemplateResource\ManageTemplates::route('/')];
    }
}
