<?php

namespace App\Filament\Merchant\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Merchant\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use App\Support\Merchant;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

/**
 * الطلب + أزرار المتجر: قبول وبدء التحضير، جاهز، رفض.
 * نفس قواعد التطبيق بالضبط (OrderService) — ولا زر يتجاوزها.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'طلب #'.$this->getRecord()->code;
    }

    protected function getHeaderActions(): array
    {
        /** @var Order $o */
        $o = $this->getRecord();
        $waiting = $o->awaiting_customer_at !== null;

        return [
            Action::make('accept')
                ->label('قبول وبدء التحضير')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => in_array($this->getRecord()->status, [OrderStatus::Pending, OrderStatus::Accepted], true) && ! $waiting)
                ->schema([
                    TextInput::make('prep_time_minutes')->label('مدة التحضير (دقيقة)')
                        ->numeric()->required()->minValue(1)->maxValue(600)
                        ->default(fn () => $o->prep_time_minutes ?: (Merchant::store()?->prep_time_minutes ?: 20)),
                ])
                ->action(fn (array $data) => $this->move(OrderStatus::Preparing, $data, 'تم قبول الطلب وبدا التحضير')),

            Action::make('ready')
                ->label('الطلب جاهز')
                ->icon('heroicon-o-shopping-bag')
                ->color('info')
                ->requiresConfirmation()
                ->modalDescription('نبلّغو السائق إن الطلب جاهز للاستلام.')
                ->visible(fn () => ! $waiting && (in_array($this->getRecord()->status, [OrderStatus::Accepted, OrderStatus::Preparing], true)
                    || ($this->getRecord()->status === OrderStatus::Assigned && $this->getRecord()->ready_at === null)))
                ->action(fn () => $this->move(OrderStatus::Ready, [], 'تم: الطلب جاهز')),

            Action::make('reject')
                ->label($o->status === OrderStatus::Pending ? 'رفض الطلب' : 'إلغاء الطلب')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->getRecord()->status->canMoveTo(OrderStatus::Cancelled)
                    && in_array($this->getRecord()->status, [OrderStatus::Pending, OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::Ready], true))
                ->schema([
                    Textarea::make('reason')->label('السبب (يوصل للزبون)')->required()->maxLength(200)
                        ->placeholder('مثلاً: المطبخ مسكّر، صنف خالص...'),
                ])
                ->requiresConfirmation()
                ->action(fn (array $data) => $this->move(OrderStatus::Cancelled, $data, 'تم إلغاء الطلب')),
        ];
    }

    private function move(OrderStatus $to, array $data, string $done): void
    {
        $order = $this->getRecord()->fresh();
        $orders = app(OrderService::class);

        // حتى لو الصفحة قديمة: الطلب لازم يكون من متجره
        abort_unless($order && $order->store_id === Merchant::storeId(), 403);

        try {
            if ($to === OrderStatus::Ready && $order->status === OrderStatus::Assigned) {
                $orders->markReadyWhileAssigned($order, auth()->user());
            } else {
                $orders->transition($order, $to, auth()->user(), array_intersect_key($data, array_flip(['prep_time_minutes', 'reason'])));
            }
            Notification::make()->title($done)->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title('ما تمش')->body(collect($e->errors())->flatten()->first())->danger()->send();
        }

        $this->record = $order->fresh();
    }
}
