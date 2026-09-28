<?php

namespace App\Filament\Resources\Tickets\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Ticket;
use App\Services\SupportService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/** المحادثة + الرد — الرد يوصل للتطبيق كإشعار */
class ViewTicket extends Page
{
    use InteractsWithRecord;

    protected static string $resource = TicketResource::class;

    protected string $view = 'filament.tickets.view';

    public ?array $reply = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        abort_unless(TicketResource::canView($this->record), 403);
        $this->replyForm->fill();
        $this->markRead();
    }

    public function getTitle(): string
    {
        return 'تذكرة '.$this->ticket()->code;
    }

    public function ticket(): Ticket
    {
        return $this->getRecord();
    }

    /** الإدارة فتحت التذكرة = قراتها */
    public function markRead(): void
    {
        if ($this->ticket()->admin_unread) {
            $this->ticket()->update(['admin_unread' => false]);
        }
    }

    public function replyForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('reply')
            ->components([
                Textarea::make('body')->hiddenLabel()->placeholder('اكتب ردك هني… يوصل للتطبيق كإشعار')->rows(3)->maxLength(2000),
                FileUpload::make('image')->hiddenLabel()->image()->disk('public')
                    ->directory(fn () => 'tickets/'.$this->ticket()->id)->maxSize(5120),
            ]);
    }

    public function send(bool $close = false): void
    {
        $data = $this->replyForm->getState();
        try {
            app(SupportService::class)->staffReply($this->ticket(), auth()->user(), $data['body'] ?? null, $data['image'] ?? null, $close);
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

            return;
        }
        $this->replyForm->fill();
        $this->record = $this->ticket()->fresh();
        Notification::make()->title($close ? 'انبعت الرد وتقفلت التذكرة' : 'انبعت الرد')->success()->send();
    }

    public function sendAndClose(): void
    {
        $this->send(true);
    }

    /** تحديث المحادثة (wire:poll) */
    public function refreshTicket(): void
    {
        $this->record = $this->ticket()->fresh();
        $this->markRead();
    }

    protected function getHeaderActions(): array
    {
        $t = $this->ticket();

        return [
            Action::make('order')->label('فتح الطلب '.($t->order?->code ?? ''))
                ->icon('heroicon-o-shopping-bag')->color('gray')
                ->visible(fn () => $this->ticket()->order_id !== null)
                ->url(fn () => OrderResource::getUrl('view', ['record' => $this->ticket()->order_id]))
                ->openUrlInNewTab(),
            Action::make('assign')->label('مسؤوليتي')->icon('heroicon-o-user')->color('gray')
                ->visible(fn () => $this->ticket()->assigned_to !== auth()->id())
                ->action(function () {
                    $this->ticket()->update(['assigned_to' => auth()->id()]);
                    $this->record = $this->ticket()->fresh();
                }),
            Action::make('close')->label('قفل التذكرة')->icon('heroicon-o-lock-closed')->color('danger')
                ->visible(fn () => ! $this->ticket()->isClosed())
                ->requiresConfirmation()
                ->action(function () {
                    app(SupportService::class)->close($this->ticket(), auth()->user());
                    $this->record = $this->ticket()->fresh();
                }),
            Action::make('reopen')->label('إعادة فتح')->icon('heroicon-o-lock-open')->color('warning')
                ->visible(fn () => $this->ticket()->isClosed())
                ->action(function () {
                    $this->ticket()->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
                    $this->record = $this->ticket()->fresh();
                }),
        ];
    }
}
