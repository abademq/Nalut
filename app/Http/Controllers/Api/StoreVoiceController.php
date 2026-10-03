<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StoreVoiceService;
use App\Support\LocalDay;
use App\Support\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** v88: الأوامر الصوتية في تطبيق المتجر */
class StoreVoiceController extends Controller
{
    public function __construct(private readonly StoreVoiceService $voice) {}

    private function store(Request $request)
    {
        abort_unless(StoreVoiceService::enabled(), 403, 'الأوامر الصوتية موقوفة من الإدارة.');

        return $request->user()->store ?? abort(403, 'هذا الحساب غير مرتبط بمتجر.');
    }

    /**
     * يفهم الجملة ويرجّع قائمة الإجراءات.
     * auto=1 والتأكيد مطفي من اللوحة (وما فيش أمر خطير) = ينفّذ فوراً ويرجّع النتيجة.
     */
    public function interpret(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate([
            'text' => ['required', 'string', 'min:2', 'max:300'],
            'auto' => ['nullable', 'boolean'],
        ]);

        $key = 'voice:'.$store->id.':'.now(LocalDay::timezone())->format('Y-m-d');
        $used = (int) Cache::get($key, 0);
        abort_if($used >= (int) Options::get('voice.daily_limit'), 429, 'وصلت الحد اليومي للأوامر الصوتية — كمّل من الشاشات العادية.');
        Cache::put($key, $used + 1, now()->addDay());

        $res = $this->voice->interpret($store, $request->user(), $data['text']);

        if (($data['auto'] ?? false) && ! $res['needs_confirm'] && $res['token']) {
            $res['executed'] = $this->voice->execute($store, $request->user(), $res['token']);
            $res['token'] = null;
        }

        return response()->json($res);
    }

    public function execute(Request $request): JsonResponse
    {
        $store = $this->store($request);
        $data = $request->validate([
            'token' => ['required', 'string', 'max:20000'],
            'skip' => ['nullable', 'array', 'max:500'],
            'skip.*' => ['integer', 'min:0'],
        ]);

        return response()->json($this->voice->execute($store, $request->user(), $data['token'], $data['skip'] ?? []));
    }
}
