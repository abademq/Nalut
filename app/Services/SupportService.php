<?php

namespace App\Services;

use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Options;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * تذاكر الدعم: الفتح، الرسائل، الردود، والقفل.
 * كل تغيير يمر من هني باش التنبيهات (الإدارة والتطبيق) ما تفوتش.
 */
class SupportService
{
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public function open(User $user, string $app, string $category, string $body, ?string $subject = null, ?int $orderId = null, mixed $image = null): Ticket
    {
        if (! (bool) Options::get('support.enabled')) {
            throw ValidationException::withMessages(['body' => 'الدعم من التطبيق موقوف حالياً.']);
        }
        if (! array_key_exists($category, Ticket::categoriesFor($app))) {
            throw ValidationException::withMessages(['category' => 'اختار نوع المشكلة.']);
        }

        $max = (int) Options::get('support.max_open');
        if (Ticket::where('user_id', $user->id)->open()->count() >= $max) {
            throw ValidationException::withMessages(['body' => "عندك $max تذاكر مفتوحة — كمّل فيهم أو اقفل وحدة قبل ما تفتح جديدة."]);
        }

        $order = $orderId ? $this->ownOrder($user, $orderId) : null;

        $ticket = DB::transaction(function () use ($user, $app, $category, $body, $subject, $order, $image) {
            $ticket = Ticket::create([
                'user_id' => $user->id,
                'app' => $app,
                'category' => $category,
                'subject' => mb_substr(trim($subject ?: Str::limit(trim($body), 60, '…')), 0, 120),
                'order_id' => $order?->id,
                'status' => 'open',
                'admin_unread' => true,
                'last_message_at' => now(),
            ]);
            $ticket->update(['code' => 'TK'.str_pad((string) $ticket->id, 5, '0', STR_PAD_LEFT)]);

            $ticket->messages()->create([
                'user_id' => $user->id,
                'is_staff' => false,
                'body' => trim($body),
                'image' => $this->storeImage($ticket, $image),
            ]);

            return $ticket;
        });

        AdminAlerts::send(
            "تذكرة جديدة {$ticket->code} — ".(Ticket::APPS[$app] ?? $app),
            "{$ticket->categoryLabel()}: {$ticket->subject}",
            $this->adminUrl($ticket), 'info', "ticket-new:{$ticket->id}", 'support.manage'
        );

        return $ticket->fresh(['messages', 'order', 'lastMessage']);
    }

    public function reply(Ticket $ticket, User $user, ?string $body, mixed $image = null): TicketMessage
    {
        abort_unless($ticket->user_id === $user->id, 404);
        $this->ensureContent($body, $image);

        $msg = DB::transaction(function () use ($ticket, $user, $body, $image) {
            $msg = $ticket->messages()->create([
                'user_id' => $user->id,
                'is_staff' => false,
                'body' => $body !== null ? trim($body) : null,
                'image' => $this->storeImage($ticket, $image),
            ]);
            // رد على تذكرة مقفولة يرجّعها مفتوحة
            $ticket->update(['status' => 'open', 'admin_unread' => true, 'last_message_at' => now(),
                'closed_at' => null, 'closed_by' => null]);

            return $msg;
        });

        AdminAlerts::send(
            "رد جديد على {$ticket->code}",
            Str::limit((string) ($body ?: '📷 صورة'), 120),
            $this->adminUrl($ticket), 'info', "ticket-msg:{$ticket->id}:{$msg->id}", 'support.manage'
        );

        return $msg;
    }

    public function staffReply(Ticket $ticket, User $admin, ?string $body, ?string $imagePath = null, bool $close = false): TicketMessage
    {
        $this->ensureContent($body, $imagePath);

        $msg = DB::transaction(function () use ($ticket, $admin, $body, $imagePath, $close) {
            $msg = $ticket->messages()->create([
                'user_id' => $admin->id,
                'is_staff' => true,
                'body' => $body !== null ? trim($body) : null,
                'image' => $imagePath,
            ]);
            $ticket->update([
                'status' => $close ? 'closed' : 'answered',
                'admin_unread' => false,
                'user_unread' => $ticket->user_unread + 1,
                'last_message_at' => now(),
                'assigned_to' => $ticket->assigned_to ?? $admin->id,
                'closed_at' => $close ? now() : null,
                'closed_by' => $close ? $admin->id : null,
            ]);

            return $msg;
        });

        PushService::toUser(
            $ticket->user,
            "رد من الدعم الفني — {$ticket->code}",
            Str::limit((string) ($body ?: '📷 صورة'), 120),
            ['type' => 'ticket', 'ticket_id' => (string) $ticket->id],
            $ticket->app
        );

        return $msg;
    }

    public function close(Ticket $ticket, User $by): Ticket
    {
        $ticket->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id, 'admin_unread' => false]);

        return $ticket;
    }

    /** الإدارة ردّت وما جاش رد من فترة — تتقفل لحالها */
    public function autoClose(): int
    {
        $days = (int) Options::get('support.auto_close_days');
        if ($days <= 0) {
            return 0;
        }

        return Ticket::where('status', 'answered')
            ->where('last_message_at', '<', now()->subDays($days))
            ->update(['status' => 'closed', 'closed_at' => now()]);
    }

    public function markReadByUser(Ticket $ticket): void
    {
        if ($ticket->user_unread) {
            $ticket->update(['user_unread' => 0]);
        }
    }

    /** الطلب لازم يكون تابع للشخص: زبونه، سائقه، أو متجره */
    private function ownOrder(User $user, int $orderId): Order
    {
        $order = Order::find($orderId);
        $mine = $order && ($order->customer_id === $user->id || $order->driver_id === $user->id
            || ($order->store && $order->store->user_id === $user->id));
        if (! $mine) {
            throw ValidationException::withMessages(['order_id' => 'الطلب هذا مش تابعلك.']);
        }

        return $order;
    }

    private function ensureContent(?string $body, mixed $image): void
    {
        if (trim((string) $body) === '' && blank($image)) {
            throw ValidationException::withMessages(['body' => 'اكتب رسالة أو حط صورة.']);
        }
    }

    /**
     * صورة المرفق: ملف مرفوع، أو base64 (من التطبيقات والموقع)، أو مسار جاهز (من اللوحة).
     */
    public function storeImage(Ticket $ticket, mixed $image): ?string
    {
        if (blank($image)) {
            return null;
        }

        $dir = "tickets/{$ticket->id}";

        if ($image instanceof UploadedFile) {
            return $image->store($dir, 'public');
        }

        $data = (string) $image;
        if (str_contains($data, ',') && str_starts_with($data, 'data:')) {
            $data = substr($data, strpos($data, ',') + 1);
        }
        $bin = base64_decode($data, true);
        if ($bin === false || strlen($bin) > self::MAX_IMAGE_BYTES) {
            throw ValidationException::withMessages(['image' => 'الصورة مش صالحة أو كبيرة (لحد 5 ميغا).']);
        }
        $info = @getimagesizefromstring($bin);
        $ext = match ($info['mime'] ?? null) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            default => null,
        };
        if (! $ext) {
            throw ValidationException::withMessages(['image' => 'الملف لازم يكون صورة (JPG أو PNG).']);
        }

        $path = "$dir/".Str::random(32).".$ext";
        Storage::disk('public')->put($path, $bin);

        return $path;
    }

    public function adminUrl(Ticket $ticket): string
    {
        return TicketResource::getUrl('view', ['record' => $ticket->id], panel: 'admin');
    }
}
