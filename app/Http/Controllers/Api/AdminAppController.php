<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Pages\OperationsSettings;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Campaign;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DeliveryIssueService;
use App\Services\DriverLocationService;
use App\Services\GeoService;
use App\Services\OrderService;
use App\Services\PushService;
use App\Services\SupportService;
use App\Support\Activity;
use App\Support\IssueDecisions;
use App\Support\LocalDay;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * تطبيق الإدارة (ازانكس إدارة): الطلبات، تذاكر الدعم، إعدادات التشغيل، والمتاجر.
 * كل عملية تحترم صلاحيات الحساب نفسها اللي في لوحة التحكم.
 */
class AdminAppController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly SupportService $support,
    ) {}

    // ===== الدخول =====

    /** بالبريد أو رقم الهاتف + كلمة المرور — حسابات الإدارة بس */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
            'fcm_token' => ['nullable', 'string'],
            'device' => ['nullable', 'string', 'max:60'],
        ]);

        $login = trim($data['login']);
        $user = User::where(str_contains($login, '@') ? 'email' : 'phone', $login)->first();

        if (! $user || blank($user->password) || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['login' => 'البيانات غير صحيحة.']);
        }
        if (! $user->hasRole(UserRole::Admin)) {
            throw ValidationException::withMessages(['login' => 'الحساب هذا مش حساب إدارة.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'الحساب موقوف.']);
        }

        $user->setPushToken('admin', $data['fcm_token'] ?? null);
        Activity::record('admin_app.login', "دخول تطبيق الإدارة: {$user->name}", $user);

        return response()->json([
            'token' => $user->createToken('admin-app:'.($data['device'] ?? 'phone'), ['admin'])->plainTextToken,
            'user' => $this->me($user),
        ]);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->me($request->user())]);
    }

    public function pushToken(Request $request): JsonResponse
    {
        $data = $request->validate(['fcm_token' => ['required', 'string']]);
        $request->user()->setPushToken('admin', $data['fcm_token']);

        return response()->json(['ok' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        // ما عادش توصله إشعارات الإدارة على الهاتف هذا
        $tokens = $user->fcm_tokens ?? [];
        unset($tokens['admin']);
        $user->forceFill(['fcm_tokens' => $tokens ?: null])->saveQuietly();
        $user->currentAccessToken()?->delete();

        return response()->json(['ok' => true]);
    }

    private function me(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'super' => empty($user->permissions),
            'can' => [
                'orders' => $user->hasPermission('orders.view'),
                'orders_manage' => $user->hasPermission('orders.manage'),
                'support' => $user->hasPermission('support.manage'),
                'settings' => $user->hasPermission('settings.manage'),
                'stores' => $user->hasPermission('stores.manage'),
                'finance' => $user->hasPermission('finance.view'),
                'messages' => $user->hasPermission('messages.manage'),
            ],
        ];
    }

    // ===== الملخص (الشاشة الرئيسية) =====

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        [$from, $to] = LocalDay::range();

        $out = ['alerts_unread' => $user->unreadNotifications()->count()];

        if ($user->hasPermission('orders.view')) {
            $active = Order::active()->visibleToStaff()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');
            $out['orders'] = [
                'active' => (int) $active->sum(),
                'by_status' => collect(OrderStatus::active())->mapWithKeys(fn ($s) => [$s => (int) ($active[$s] ?? 0)]),
                'pending' => (int) ($active['pending'] ?? 0),
                // جاهز وما خذاهش حد (توصيل) — يحتاج تدخّل
                'ready_no_driver' => Order::where('status', 'ready')->whereNull('driver_id')->where('fulfillment', 'delivery')->count(),
                'today' => Order::whereBetween('created_at', [$from, $to])->visibleToStaff()->count(),
                'today_delivered' => Order::where('status', 'delivered')->whereBetween('delivered_at', [$from, $to])->count(),
                'today_cancelled' => Order::whereIn('status', ['cancelled', 'failed'])->whereBetween('cancelled_at', [$from, $to])->count(),
            ];
        }
        if ($user->hasPermission('finance.view')) {
            $out['sales_today'] = round((float) Order::where('status', 'delivered')->whereBetween('delivered_at', [$from, $to])->sum('total'), 2);
            $out['commission_today'] = round((float) Order::where('status', 'delivered')->whereBetween('delivered_at', [$from, $to])->sum('commission_amount'), 2);
        }
        if ($user->hasPermission('support.manage')) {
            $out['tickets'] = [
                'open' => Ticket::where('status', 'open')->count(),
                'unread' => Ticket::where('admin_unread', true)->where('status', '!=', 'closed')->count(),
            ];
        }
        $out['drivers_online'] = User::withRole('driver')->whereHas('driverProfile', fn ($q) => $q->where('is_online', true))->count();

        return response()->json($out);
    }

    // ===== الطلبات =====

    public function orders(Request $request): JsonResponse
    {
        $this->need($request, 'orders.view');
        $request->validate(['status' => ['nullable', 'string'], 'q' => ['nullable', 'string', 'max:30'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $final = [OrderStatus::Delivered->value, OrderStatus::Cancelled->value, OrderStatus::Failed->value];

        $orders = Order::query()->visibleToStaff()
            ->when(in_array($request->status, [null, '', 'active'], true), fn ($q) => $q->active())
            ->when($request->status === 'history', fn ($q) => $q->whereIn('status', $final))
            ->when($request->status === 'attention', fn ($q) => $q->where(fn ($w) => $w
                ->where('status', 'pending')->where('created_at', '<', now()->subMinutes(max(1, (int) Options::get('alerts.pending_minutes'))))
                ->orWhere(fn ($r) => $r->where('status', 'ready')->whereNull('driver_id')->where('fulfillment', 'delivery'))
                ->orWhereHas('openIssue')))
            ->when($request->status && ! in_array($request->status, ['active', 'history', 'attention', 'all'], true),
                fn ($q) => $q->where('status', $request->status))
            ->when($request->date, function ($q) use ($request) {
                [$from, $to] = LocalDay::range($request->date);
                $q->whereBetween('created_at', [$from, $to]);
            })
            ->when($request->q, fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', '%'.$request->q.'%')
                ->orWhere('customer_phone', 'like', '%'.$request->q.'%')))
            ->with(['store', 'driver', 'customer', 'items'])
            ->latest()
            ->paginate(25);

        return OrderResource::collection($orders)->response();
    }

    public function order(Request $request, Order $order): JsonResponse
    {
        $this->need($request, 'orders.view');
        $order->load(['store', 'driver.driverProfile', 'customer', 'items', 'statusLogs', 'rating']);

        return response()->json([
            'data' => new OrderResource($order),
            // الانتقالات المسموحة توّا (الباقي استثنائي يحتاج force + سبب)
            'next' => collect(OrderStatus::cases())
                ->reject(fn ($s) => $s === $order->status)
                ->map(fn ($s) => ['status' => $s->value, 'label' => $s->label(), 'normal' => OrderService::canMove($order, $order->status, $s)])
                ->values(),
            'driver_location' => $order->driver_id ? DriverLocationService::get($order->driver_id) : null,
            // بلاغ السائق المفتوح + القرارات الممكنة
            'issue' => $order->openIssue ? [
                'ticket' => $order->openIssue->ticket,
                'reason' => $order->openIssue->reason_label,
                'note' => $order->openIssue->note,
                'driver' => $order->openIssue->driver?->name,
                'created_at' => $order->openIssue->created_at,
                'resolutions' => IssueDecisions::options(),
            ] : null,
        ]);
    }

    public function resolveIssue(Request $request, Order $order): JsonResponse
    {
        $this->need($request, 'orders.manage');
        $data = $request->validate([
            'resolution' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        abort_unless($order->openIssue, 422, 'ما فيش بلاغ مفتوح على الطلب.');

        app(DeliveryIssueService::class)->resolve($order->openIssue, $request->user(), $data['resolution'], $data['note'] ?? null);

        return $this->order($request, $order->fresh());
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->need($request, 'orders.manage');
        $data = $request->validate([
            'status' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:200'],
            'driver_id' => ['nullable', 'integer'],
            'prep_time_minutes' => ['nullable', 'integer', 'between:1,600'],
            'force' => ['nullable', 'boolean'],
        ]);
        $to = OrderStatus::tryFrom($data['status']) ?? throw ValidationException::withMessages(['status' => 'حالة غير معروفة.']);

        $abnormal = ! OrderService::canMove($order, $order->status, $to);
        if (($abnormal || in_array($to, [OrderStatus::Cancelled, OrderStatus::Failed], true)) && blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'اكتب السبب.']);
        }
        if ($to === OrderStatus::Assigned && empty($data['driver_id'])) {
            throw ValidationException::withMessages(['driver_id' => 'اختار السائق.']);
        }

        $order = $this->orders->transition($order, $to, $request->user(), array_filter([
            'reason' => $data['reason'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'prep_time_minutes' => $data['prep_time_minutes'] ?? null,
        ]) + ['force' => (bool) ($data['force'] ?? false)]);

        return response()->json(['data' => new OrderResource($order->load(['store', 'driver', 'customer', 'items', 'statusLogs']))]);
    }

    /** السائقين اللي ينفعو للطلب: المتاحين أول، بالأقرب للمتجر */
    public function drivers(Request $request, Order $order): JsonResponse
    {
        $this->need($request, 'orders.manage');
        $order->loadMissing('store');

        $drivers = User::withRole('driver')->where('is_active', true)
            ->whereHas('driverProfile', fn ($q) => $q->where('is_approved', true))
            ->with('driverProfile')->get()
            ->map(function (User $d) use ($order) {
                $loc = DriverLocationService::get($d->id);
                $km = ($loc && $order->store?->lat)
                    ? round(GeoService::distanceKm((float) $loc['lat'], (float) $loc['lng'], (float) $order->store->lat, (float) $order->store->lng), 1)
                    : null;

                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'phone' => $d->phone,
                    'online' => (bool) $d->driverProfile?->is_online,
                    'active_orders' => $d->driverProfile?->activeOrders()->count() ?? 0,
                    'distance_km' => $km,
                    'can_accept' => (bool) $d->driverProfile?->canAccept($order),
                ];
            })
            ->sortBy([['online', 'desc'], fn ($a, $b) => ($a['distance_km'] ?? 999) <=> ($b['distance_km'] ?? 999)])
            ->values();

        return response()->json(['data' => $drivers]);
    }

    // ===== تذاكر الدعم =====

    public function tickets(Request $request): JsonResponse
    {
        $this->need($request, 'support.manage');
        $status = $request->query('status', 'open');

        $tickets = Ticket::query()
            ->when($status === 'open', fn ($q) => $q->where('status', 'open'))
            ->when($status === 'active', fn ($q) => $q->where('status', '!=', 'closed'))
            ->when(in_array($status, ['answered', 'closed'], true), fn ($q) => $q->where('status', $status))
            ->when($request->query('app'), fn ($q, $app) => $q->where('app', $app))
            ->when($request->query('q'), fn ($q, $s) => $q->where(fn ($w) => $w->where('code', 'like', "%$s%")->orWhere('subject', 'like', "%$s%")
                ->orWhereHas('user', fn ($u) => $u->where('phone', 'like', "%$s%")->orWhere('name', 'like', "%$s%"))))
            ->with(['user', 'order:id,code', 'lastMessage', 'assignee:id,name'])
            ->orderByDesc('admin_unread')->orderByDesc('last_message_at')
            ->paginate(30);

        return response()->json([
            'data' => $tickets->getCollection()->map(fn (Ticket $t) => $this->ticket($t))->values(),
            'meta' => ['current_page' => $tickets->currentPage(), 'last_page' => $tickets->lastPage(), 'total' => $tickets->total()],
        ]);
    }

    public function showTicket(Request $request, Ticket $ticket): JsonResponse
    {
        $this->need($request, 'support.manage');
        if ($ticket->admin_unread) {
            $ticket->update(['admin_unread' => false]);
        }
        $ticket->load(['user', 'order:id,code,status', 'messages.user:id,name', 'lastMessage', 'assignee:id,name']);

        return response()->json(['data' => $this->ticket($ticket, true)]);
    }

    public function replyTicket(Request $request, Ticket $ticket): JsonResponse
    {
        $this->need($request, 'support.manage');
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable'],
            'close' => ['nullable', 'boolean'],
        ]);

        $path = filled($data['image'] ?? null) || $request->hasFile('image')
            ? $this->support->storeImage($ticket, $request->file('image') ?? $data['image'])
            : null;
        $this->support->staffReply($ticket, $request->user(), $data['body'] ?? null, $path, (bool) ($data['close'] ?? false));

        return $this->showTicket($request, $ticket->fresh());
    }

    public function closeTicket(Request $request, Ticket $ticket): JsonResponse
    {
        $this->need($request, 'support.manage');
        $this->support->close($ticket, $request->user());

        return response()->json(['data' => $this->ticket($ticket->fresh(['user', 'lastMessage']))]);
    }

    public function assignTicket(Request $request, Ticket $ticket): JsonResponse
    {
        $this->need($request, 'support.manage');
        $ticket->update(['assigned_to' => $request->user()->id]);

        return response()->json(['data' => $this->ticket($ticket->fresh(['user', 'lastMessage', 'assignee:id,name']))]);
    }

    private function ticket(Ticket $t, bool $messages = false): array
    {
        $out = [
            'id' => $t->id,
            'code' => $t->code,
            'app' => $t->app,
            'app_label' => Ticket::APPS[$t->app] ?? $t->app,
            'category_label' => $t->categoryLabel(),
            'subject' => $t->subject,
            'status' => $t->status,
            'status_label' => $t->statusLabel(),
            'unread' => (bool) $t->admin_unread,
            'user' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name, 'phone' => $t->user->phone] : null,
            'order_id' => $t->order_id,
            'order_code' => $t->order?->code,
            'assignee' => $t->assignee?->name,
            'last_message' => $t->lastMessage ? mb_substr((string) ($t->lastMessage->body ?: '📷 صورة'), 0, 90) : null,
            'last_message_at' => $t->last_message_at,
            'created_at' => $t->created_at,
        ];

        if ($messages) {
            $out['messages'] = $t->messages->map(fn ($m) => [
                'id' => $m->id,
                'from' => $m->is_staff ? 'staff' : 'user',
                // داخل الإدارة نوريو اسم الموظف اللي ردّ
                'sender' => $m->is_staff ? ($m->user?->name ?? 'الدعم الفني') : ($t->user?->name ?? 'المستخدم'),
                'body' => $m->body,
                'image' => $m->imageUrl(),
                'created_at' => $m->created_at,
            ])->values();
        }

        return $out;
    }

    // ===== إعدادات التشغيل =====

    public function settings(Request $request): JsonResponse
    {
        $this->need($request, 'settings.manage');
        $defs = Options::definitions();

        $groups = collect(OperationsSettings::SECTIONS)->map(fn ($label, $prefix) => [
            'key' => $prefix,
            'label' => $label,
            'fields' => collect($defs)
                ->filter(fn ($d, $k) => str_starts_with($k, "$prefix."))
                ->map(fn ($d, $k) => [
                    'key' => $k,
                    'type' => $d['type'],
                    'label' => $d['label'] ?? $k,
                    'help' => $d['help'] ?? null,
                    'choices' => $d['choices'] ?? null,
                    'min' => $d['min'] ?? null,
                    'max' => $d['max'] ?? null,
                    'value' => $d['type'] === 'list' ? implode(',', Options::get($k)) : Options::get($k),
                ])->values(),
        ])->filter(fn ($g) => $g['fields']->isNotEmpty())->values();

        return response()->json(['data' => $groups]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $this->need($request, 'settings.manage');
        $data = $request->validate(['values' => ['required', 'array', 'min:1']]);
        $defs = Options::definitions();
        $allowed = array_keys(OperationsSettings::SECTIONS);
        $errors = [];
        $saved = [];

        foreach ($data['values'] as $key => $value) {
            $def = $defs[$key] ?? null;
            if (! $def || ! in_array(strtok($key, '.'), $allowed, true)) {
                $errors[$key] = 'خيار غير معروف.';

                continue;
            }

            $value = match ($def['type']) {
                'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
                'list' => implode(',', Options::parseList((string) $value)),
                default => trim((string) $value),
            };

            if (in_array($def['type'], ['int', 'float'], true)) {
                if (! is_numeric($value)) {
                    $errors[$key] = 'لازم رقم.';

                    continue;
                }
                if ((isset($def['min']) && $value < $def['min']) || (isset($def['max']) && $value > $def['max'])) {
                    $errors[$key] = "بين {$def['min']} و {$def['max']}.";

                    continue;
                }
            }
            if (! empty($def['choices']) && ! array_key_exists($value, $def['choices'])) {
                $errors[$key] = 'اختيار غير صالح.';

                continue;
            }

            Setting::put("opt.$key", $value);
            $saved[] = $key;
        }

        if ($saved) {
            Activity::record('settings.updated', 'تطبيق الإدارة: تعديل '.implode('، ', $saved), $request->user());
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return response()->json(['message' => 'تم الحفظ', 'saved' => $saved]);
    }

    // ===== المتاجر (فتح وإغلاق) =====

    public function stores(Request $request): JsonResponse
    {
        $this->need($request, 'orders.view');

        return response()->json(['data' => Store::where('is_active', true)->orderBy('name')->get()->map(fn (Store $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'phone' => $s->phone,
            'is_accepting' => $s->isAcceptingOrders(),
            'status_text' => $s->statusText(),
            'active_orders' => $s->orders()->active()->count(),
        ])->values()]);
    }

    public function toggleStore(Request $request, Store $store): JsonResponse
    {
        $this->need($request, 'stores.manage');
        $accepting = $store->toggleManual();
        Activity::record('store.toggled', "تطبيق الإدارة: {$store->name} ".($accepting ? 'فتح' : 'سكّر'), $store);

        return response()->json(['is_accepting' => $accepting, 'status_text' => $store->fresh()->statusText()]);
    }

    // ===== التنبيهات (نفس جرس لوحة التحكم) =====

    public function alerts(Request $request): JsonResponse
    {
        $items = $request->user()->notifications()->latest()->limit(60)->get()->map(fn ($n) => [
            'id' => $n->id,
            'title' => $n->data['title'] ?? '',
            'body' => $n->data['body'] ?? '',
            'level' => $n->data['iconColor'] ?? 'info',
            'url' => $n->data['actions'][0]['url'] ?? null,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at,
        ]);

        return response()->json(['data' => $items]);
    }

    public function readAlerts(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ===== إرسال الإشعارات من تطبيق الإدارة =====

    /** آخر الإشعارات اللي انبعتت (حملات «إشعار في التطبيق») */
    public function notifications(Request $request): JsonResponse
    {
        $this->need($request, 'messages.manage');

        $list = Campaign::where('channel', 'push')->latest('id')->limit(30)->get()
            ->map(fn (Campaign $c) => [
                'id' => $c->id,
                'title' => $c->push_title,
                'body' => $c->push_body,
                'target_role' => $c->target_role,
                'target_label' => Campaign::ROLES[$c->target_role] ?? $c->target_role,
                'kind' => $c->audience_params['kind'] ?? 'promo',
                'audience' => $c->audience,
                'status' => $c->status,
                'status_label' => Campaign::STATUSES[$c->status] ?? $c->status,
                'total' => (int) $c->total,
                'sent' => (int) $c->sent,
                'failed' => (int) $c->failed,
                'created_at' => $c->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $list]);
    }

    /**
     * إشعار جديد: لمجموعة (زبائن / سائقين / متاجر) أو لرقم واحد.
     * dry_run=1 = يرجع عدد اللي بيوصلهم بس (قبل التأكيد).
     */
    public function sendNotification(Request $request): JsonResponse
    {
        $this->need($request, 'messages.manage');

        $data = $request->validate([
            'target_role' => ['required', 'in:customer,driver,store'],
            'kind' => ['required', 'in:service,promo'],
            'audience' => ['nullable', 'in:all,active,inactive'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'phone' => ['nullable', 'string', 'max:20'],
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:300'],
            'link' => ['nullable', 'string', 'max:200'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        // العروض للزبائن بس — السائقين والمتاجر تنبيهات تشغيلية
        if ($data['kind'] === 'promo' && $data['target_role'] !== 'customer') {
            $data['kind'] = 'service';
        }

        // رقم واحد: إرسال فوري
        if (filled($data['phone'] ?? null)) {
            $digits = preg_replace('/\D/', '', (string) $data['phone']);
            $tail = substr($digits, -9);
            $user = strlen($tail) < 7 ? null : User::withRole($data['target_role'])->where('phone', 'like', '%'.$tail)->first();
            if (! $user) {
                throw ValidationException::withMessages(['phone' => 'ما لقيناش حساب بالرقم هذا في الفئة المختارة.']);
            }
            if ($request->boolean('dry_run')) {
                return response()->json(['recipients' => 1, 'name' => $user->name]);
            }
            $ok = PushService::toUser($user, $data['title'], $data['body'],
                array_filter(['type' => $data['kind'] === 'service' ? 'notice' : 'promo', 'link' => $data['link'] ?? null]), $data['target_role']);
            MessageLog::create(['channel' => 'push', 'phone' => (string) $user->phone, 'context' => 'admin_app',
                'status' => $ok ? 'sent' : 'failed', 'error' => $ok ? null : 'ما عندوش التطبيق (ما فيش توكن إشعارات)', 'created_at' => now()]);
            Activity::record('notify.single', "إشعار لـ {$user->name}: {$data['title']}", $user);

            return response()->json(['sent' => $ok, 'recipients' => 1, 'message' => $ok ? 'وصل الإشعار.' : 'الحساب هذا ما عندوش التطبيق مفتوح بإشعارات.']);
        }

        $campaign = new Campaign([
            'title' => 'من تطبيق الإدارة: '.$data['title'],
            'channel' => 'push',
            'target_role' => $data['target_role'],
            'push_title' => $data['title'],
            'push_body' => $data['body'],
            'push_link' => $data['link'] ?? null,
            'audience' => $data['target_role'] === 'customer' ? ($data['audience'] ?? 'all') : 'all',
            'audience_params' => ['days' => (int) ($data['days'] ?? 30), 'kind' => $data['kind']],
        ]);

        $count = $campaign->customersQuery()->count();
        if ($request->boolean('dry_run')) {
            return response()->json(['recipients' => $count]);
        }
        if ($count === 0) {
            throw ValidationException::withMessages(['audience' => 'ما فيش حد بيوصله الإشعار هذا.']);
        }

        $campaign->fill(['status' => 'queued', 'scheduled_at' => now(), 'created_by' => $request->user()->id, 'total' => $count])->save();
        Activity::record('notify.campaign', "إشعار من تطبيق الإدارة ($count): {$data['title']}", $campaign);

        return response()->json(['queued' => true, 'recipients' => $count, 'id' => $campaign->id,
            'message' => "الإشعار في الطريق لـ $count — يبدا الإرسال خلال دقيقة."], 201);
    }

    private function need(Request $request, string $permission): void
    {
        abort_unless($request->user()->hasPermission($permission), 403, 'ما عندكش صلاحية لهذا القسم.');
    }
}
