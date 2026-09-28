<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\User;
use App\Services\SupportService;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** تذاكر الدعم من التطبيقات وموقع الطلب */
class SupportController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    private function app(Request $request): string
    {
        $app = $request->header('X-App') ?: $request->input('app');

        return in_array($app, User::APPS, true) ? $app : 'customer';
    }

    /** أنواع المشاكل + هل الدعم مفعّل */
    public function categories(Request $request): JsonResponse
    {
        $app = $this->app($request);

        return response()->json([
            'enabled' => (bool) Options::get('support.enabled'),
            'categories' => collect(Ticket::categoriesFor($app))
                ->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tickets = Ticket::where('user_id', $request->user()->id)
            ->where('app', $this->app($request))
            ->with(['order:id,code', 'lastMessage'])
            ->orderByRaw("CASE WHEN status = 'closed' THEN 1 ELSE 0 END")
            ->latest('last_message_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $tickets->map->toApp()->values(),
            'unread' => (int) $tickets->sum('user_unread'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:30'],
            'subject' => ['nullable', 'string', 'max:120'],
            'body' => ['required', 'string', 'min:3', 'max:2000'],
            'order_id' => ['nullable', 'integer'],
            'image' => ['nullable'],
        ], [], ['body' => 'الرسالة']);

        $ticket = $this->support->open(
            $request->user(), $this->app($request), $data['category'], $data['body'],
            $data['subject'] ?? null, $data['order_id'] ?? null, $request->file('image') ?? $request->input('image')
        );

        return response()->json(['message' => 'وصلت تذكرتك للدعم الفني — تقدر تتابعها من هني.', 'data' => $ticket->toApp(true)], 201);
    }

    public function show(Request $request, Ticket $ticket): JsonResponse
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);
        $this->support->markReadByUser($ticket);

        return response()->json(['data' => $ticket->load(['messages', 'order:id,code', 'lastMessage'])->toApp(true)]);
    }

    public function message(Request $request, Ticket $ticket): JsonResponse
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable'],
        ]);

        $msg = $this->support->reply($ticket, $request->user(), $data['body'] ?? null, $request->file('image') ?? $request->input('image'));

        return response()->json(['data' => $msg->toApp(), 'ticket' => $ticket->fresh()->toApp()], 201);
    }

    public function close(Request $request, Ticket $ticket): JsonResponse
    {
        abort_unless($ticket->user_id === $request->user()->id, 404);
        $this->support->close($ticket, $request->user());

        return response()->json(['message' => 'تم قفل التذكرة. لو رجعت المشكلة اكتب فيها وتتفتح من جديد.', 'data' => $ticket->fresh()->toApp()]);
    }
}
