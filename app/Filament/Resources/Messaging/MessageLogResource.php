<?php

namespace App\Filament\Resources\Messaging;

use App\Models\MessageLog;
use App\Models\MessageTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** سجل كل الرسائل اللي انبعتت (أو فشلت) */
class MessageLogResource extends Resource
{
    use \App\Filament\Concerns\GuardedByPermission;

    public const PERM_VIEW = 'messages.manage';

    public const PERM_MANAGE = 'messages.manage';

    protected static ?string $model = MessageLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'الرسائل';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'message-logs';

    public static function getModelLabel(): string
    {
        return 'رسالة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'سجل الرسائل';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('الوقت')->dateTime('d/m H:i'),
                TextColumn::make('context')->label('النوع')->badge()->formatStateUsing(fn ($state) => MessageLog::CONTEXTS[$state] ?? $state),
                TextColumn::make('channel')->label('القناة')->formatStateUsing(fn ($state) => MessageTemplate::CHANNELS[$state] ?? $state),
                TextColumn::make('phone')->label('الرقم')->searchable()->fontFamily('mono'),
                TextColumn::make('template.name')->label('القالب')->placeholder('—'),
                TextColumn::make('status')->label('الحالة')->badge()
                    ->formatStateUsing(fn ($state) => MessageLog::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) { 'sent' => 'success', 'failed' => 'danger', default => 'gray' }),
                TextColumn::make('error')->label('الخطأ')->limit(60)->tooltip(fn ($state) => $state)->placeholder(''),
            ])
            ->filters([
                SelectFilter::make('context')->label('النوع')->options(MessageLog::CONTEXTS),
                SelectFilter::make('status')->label('الحالة')->options(MessageLog::STATUSES),
                SelectFilter::make('channel')->label('القناة')->options(MessageTemplate::CHANNELS),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => MessageLogResource\ListMessageLogs::route('/')];
    }
}
