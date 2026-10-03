<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\MenuSection;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\Activity;
use App\Support\LocalDay;
use App\Support\Options;
use App\Support\Pickup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * v88: الأوامر الصوتية في تطبيق المتجر.
 *
 * الهاتف يحوّل الصوت لنص، وهني نفهمو النص (بالذكاء الاصطناعي لو مضبوط، وإلا بالقواعد)
 * ونطلّعو قائمة إجراءات واضحة — كل إجراء يتأكّد إنه يخص المتجر هذا قبل ما يطلع.
 * التنفيذ يكون بـ«توكن» مشفّر فيه نفس القائمة، فالتطبيق ما يقدرش يبدّل فيها (يقدر يشيل بس).
 */
class StoreVoiceService
{
    public const TOKEN_MINUTES = 5;

    public function __construct(private readonly OrderService $orders) {}

    public static function enabled(): bool
    {
        return (bool) Options::get('voice.enabled');
    }

    public static function aiConfigured(): bool
    {
        return (bool) Options::get('voice.ai') && filled(config('services.voice_ai.key'));
    }

    // ================= الفهم =================

    /**
     * @return array{text:string, source:string, actions:array, notes:array, unclear:?string, needs_confirm:bool, token:?string, message:string}
     */
    public function interpret(Store $store, User $user, string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        $source = 'rules';
        $unclear = null;
        $intents = null;

        if (self::aiConfigured()) {
            try {
                [$intents, $unclear] = $this->aiIntents($store, $text);
                $source = 'ai';
            } catch (Throwable $e) {
                Log::warning('voice ai failed: '.$e->getMessage());
                $intents = null;
            }
        }
        if ($intents === null) {
            [$intents, $unclear] = $this->ruleIntents($store, $text);
            $source = 'rules';
        }

        [$actions, $notes] = $this->build($store, $intents);

        $danger = collect($actions)->contains(fn ($a) => $a['danger']);
        $needsConfirm = (bool) Options::get('voice.confirm') || $danger || $unclear !== null;

        Activity::record('store.voice', 'أمر صوتي: «'.mb_substr($text, 0, 150).'»', $store,
            ['source' => $source, 'actions' => array_column($actions, 'label'), 'unclear' => $unclear], $user, 'store', storeId: $store->id);

        return [
            'text' => $text,
            'source' => $source,
            'actions' => $actions,
            'notes' => $notes,
            'unclear' => $unclear,
            'needs_confirm' => $needsConfirm,
            'token' => $actions ? $this->token($store, $user, $text, $actions) : null,
            'message' => $actions
                ? (count($actions) === 1 ? 'فهمنا أمر واحد' : 'فهمنا '.count($actions).' أوامر')
                : ($notes ? implode(' · ', $notes) : 'ما فهمناش الأمر — جرّب جملة أقصر، مثلاً: «الشاورما خلصت» أو «الطلب 43 جاهز».'),
        ];
    }

    private function token(Store $store, User $user, string $text, array $actions): string
    {
        return Crypt::encryptString(json_encode([
            's' => $store->id, 'u' => $user->id, 't' => $text,
            'a' => array_map(fn ($a) => array_diff_key($a, ['label' => 1, 'danger' => 1]), $actions),
            'exp' => now()->addMinutes(self::TOKEN_MINUTES)->timestamp,
        ], JSON_UNESCAPED_UNICODE));
    }

    // ================= التنفيذ =================

    /** @param  int[]  $skip  أرقام الإجراءات اللي المتجر شالها من القائمة */
    public function execute(Store $store, User $user, string $token, array $skip = []): array
    {
        try {
            $p = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['token' => 'الأمر هذا مش صالح — قوله من جديد.']);
        }
        if (($p['s'] ?? null) !== $store->id || ($p['u'] ?? null) !== $user->id) {
            throw ValidationException::withMessages(['token' => 'الأمر هذا مش لحسابك.']);
        }
        if (($p['exp'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages(['token' => 'انتهت مهلة التأكيد — قول الأمر من جديد.']);
        }

        $skip = array_map('intval', $skip);
        $results = [];
        foreach ((array) $p['a'] as $i => $a) {
            if (in_array($i, $skip, true)) {
                continue;
            }
            // نعاودو نبنيو الوصف من الحالة الحالية (ممكن تغيّرت من وقت الفهم)
            try {
                $results[] = ['ok' => true, 'message' => $this->run($store, $user, $a)];
            } catch (ValidationException $e) {
                $results[] = ['ok' => false, 'message' => collect($e->errors())->flatten()->first() ?? 'ما تنفّذش'];
            } catch (Throwable $e) {
                report($e);
                $results[] = ['ok' => false, 'message' => 'صار خطأ — ما تنفّذش'];
            }
        }

        $ok = count(array_filter($results, fn ($r) => $r['ok']));
        Activity::record('store.voice_run', 'تنفيذ أمر صوتي: «'.mb_substr((string) ($p['t'] ?? ''), 0, 150).'» ('.$ok.'/'.count($results).')',
            $store, ['results' => $results], $user, 'store', storeId: $store->id);

        return [
            'results' => $results,
            'message' => ! $results ? 'ما تنفّذ شي.'
                : ($ok === count($results) ? 'تم ✓' : "تنفّذ $ok من ".count($results)),
        ];
    }

    private function run(Store $store, User $user, array $a): string
    {
        switch ($a['type']) {
            case 'product':
                $product = $store->products()->findOrFail($a['id']);
                if ($a['available'] && $product->isOutOfStock()) {
                    throw ValidationException::withMessages(['x' => $product->name.': '.Product::OUT_OF_STOCK_MESSAGE]);
                }
                $product->update(['is_available' => (bool) $a['available']]);

                return ($a['available'] ? 'متوفر: ' : 'موقوف: ').$product->name;

            case 'section':
                $section = $store->sections()->findOrFail($a['id']);
                $section->pause((bool) $a['available'], $a['until'] ?? null);

                return ($a['available'] ? 'القسم متوفر: ' : 'القسم موقوف: ').$section->name.(! $a['available'] && ! empty($a['until']) ? ' لين '.$a['until'] : '');

            case 'store':
                $store->refresh();
                $a['open'] ? $store->openNow() : $store->closeNow();

                return $a['open'] ? 'المتجر مفتوح — '.$store->statusText() : 'المتجر مغلق';

            case 'pickup':
                $store->update(['pickup_enabled' => (bool) $a['enabled']]);

                return $a['enabled'] ? 'الاستلام من المطعم شغّال' : 'الاستلام من المطعم موقوف';

            case 'order':
                return $this->runOrder($store, $user, $a);
        }

        throw ValidationException::withMessages(['x' => 'أمر مش معروف']);
    }

    private function runOrder(Store $store, User $user, array $a): string
    {
        /** @var Order $order */
        $order = $store->orders()->findOrFail($a['id']);
        $to = OrderStatus::from($a['status']);

        if ($order->awaiting_customer_at && $to !== OrderStatus::Cancelled) {
            throw ValidationException::withMessages(['x' => "الطلب #{$order->code} يستنى رد الزبون على الأصناف الناقصة."]);
        }

        $extra = array_filter([
            'prep_time_minutes' => $a['prep'] ?? null,
            'reason' => $a['reason'] ?? null,
        ]);

        if ($to === OrderStatus::Ready && $order->status === OrderStatus::Assigned) {
            $this->orders->markReadyWhileAssigned($order, $user);
        } else {
            // «جاهز» لطلب ما تقبلش: نقبلوه ونجهزوه في خطوة وحدة
            if ($to === OrderStatus::Ready && $order->status === OrderStatus::Pending) {
                $order = $this->orders->transition($order, OrderStatus::Preparing, $user, $extra);
            }
            $this->orders->transition($order, $to, $user, $extra);
        }

        return "الطلب #{$order->code}: ".$to->label();
    }

    // ================= بناء الإجراءات (مشترك بين الذكاء الاصطناعي والقواعد) =================

    /**
     * النوايا (intents) شكلها:
     *   product {id, available} · section {id, available, until?} · all_sections {available, except[]}
     *   all_products {available, except[]} · store {open} · pickup {enabled}
     *   order {code, status: preparing|ready|cancelled, prep?, reason?}
     * أي معرّف مش للمتجر هذا ينرمى.
     *
     * @return array{0: array, 1: array}
     */
    private function build(Store $store, array $intents): array
    {
        $sections = $store->sections()->get()->keyBy('id');
        $actions = [];
        $notes = [];

        $put = function (array $a, string $label, bool $danger = false) use (&$actions) {
            $key = $a['type'].':'.($a['id'] ?? '');
            unset($actions[$key]); // آخر واحد يغلب
            $actions[$key] = $a + ['label' => $label, 'danger' => $danger];
        };

        $section = function (MenuSection $s, bool $available, ?string $until) use ($put, &$notes) {
            if ($s->isOrderable() === $available && ! ($until && ! $available)) {
                $notes[] = 'قسم '.$s->name.($available ? ' متوفر من قبل' : ' موقوف من قبل');

                return;
            }
            $put(['type' => 'section', 'id' => $s->id, 'available' => $available, 'until' => $available ? null : $until],
                ($available ? 'تشغيل قسم «' : 'إيقاف قسم «').$s->name.'»'.(! $available && $until ? ' لين الساعة '.$until : ''));
        };

        $product = function (Product $p, bool $available) use ($put, &$notes) {
            if ((bool) $p->is_available === $available) {
                $notes[] = $p->name.($available ? ' متوفر من قبل' : ' موقوف من قبل');

                return;
            }
            if ($available && $p->isOutOfStock()) {
                $notes[] = $p->name.': الكمية خالصة — زيد كمية من شاشة المنتج';

                return;
            }
            $put(['type' => 'product', 'id' => $p->id, 'available' => $available],
                ($available ? 'تشغيل «' : 'إيقاف «').$p->name.'»');
        };

        foreach ($intents as $in) {
            $type = $in['type'] ?? null;
            $available = (bool) ($in['available'] ?? false);
            $until = self::cleanUntil($in['until'] ?? null);

            switch ($type) {
                case 'section':
                    if ($s = $sections->get((int) ($in['id'] ?? 0))) {
                        $section($s, $available, $until);
                    }
                    break;

                case 'product':
                    if ($p = $store->products()->find((int) ($in['id'] ?? 0))) {
                        $product($p, $available);
                    }
                    break;

                case 'all_sections':
                    $except = array_map('intval', (array) ($in['except'] ?? []));
                    foreach ($sections as $s) {
                        $section($s, in_array($s->id, $except, true) ? ! $available : $available, null);
                    }
                    break;

                case 'all_products':
                    $except = array_map('intval', (array) ($in['except'] ?? []));
                    foreach ($store->products()->where('is_visible', true)->limit(300)->get() as $p) {
                        $product($p, in_array($p->id, $except, true) ? ! $available : $available);
                    }
                    break;

                case 'store':
                    $open = (bool) ($in['open'] ?? false);
                    if ($store->isAcceptingOrders() === $open) {
                        $notes[] = $open ? 'المتجر مفتوح من قبل' : 'المتجر مغلق من قبل';
                        break;
                    }
                    $put(['type' => 'store', 'open' => $open], $open ? 'فتح المتجر' : 'إغلاق المتجر (ما يستقبلش طلبات)', ! $open);
                    break;

                case 'pickup':
                    if (! Pickup::enabled()) {
                        $notes[] = 'الاستلام من المطعم مش مفعّل من الإدارة';
                        break;
                    }
                    $enabled = (bool) ($in['enabled'] ?? false);
                    if ((bool) $store->pickup_enabled === $enabled) {
                        $notes[] = $enabled ? 'الاستلام شغّال من قبل' : 'الاستلام موقوف من قبل';
                        break;
                    }
                    $put(['type' => 'pickup', 'enabled' => $enabled], $enabled ? 'تشغيل الاستلام من المطعم' : 'إيقاف الاستلام من المطعم');
                    break;

                case 'order':
                    $status = $in['status'] ?? null;
                    if (! in_array($status, ['preparing', 'ready', 'cancelled'], true)) {
                        break;
                    }
                    $order = $this->findOrder($store, (string) ($in['code'] ?? ''));
                    if (! $order) {
                        $notes[] = 'ما لقيناش طلب شغّال رقمه '.($in['code'] ?? '؟');
                        break;
                    }
                    $to = OrderStatus::from($status);
                    $ok = $order->status->canMoveTo($to)
                        || ($to === OrderStatus::Ready && in_array($order->status, [OrderStatus::Pending, OrderStatus::Assigned], true));
                    if (! $ok || ($to === OrderStatus::Ready && $order->status === OrderStatus::Assigned && $order->ready_at)) {
                        $notes[] = "الطلب #{$order->code} حالته «{$order->status->label()}» — ما ينفعش «{$to->label()}»";
                        break;
                    }
                    $prep = isset($in['prep']) ? max(1, min(600, (int) $in['prep'])) : null;
                    $reason = isset($in['reason']) ? mb_substr(trim((string) $in['reason']), 0, 200) : null;
                    if ($to === OrderStatus::Cancelled && ! $reason) {
                        $reason = 'المتجر رفض الطلب';
                    }
                    $label = match ($to) {
                        OrderStatus::Preparing => "قبول الطلب #{$order->code} وبدء التحضير".($prep ? " ($prep دقيقة)" : ''),
                        OrderStatus::Ready => "الطلب #{$order->code} جاهز",
                        default => "رفض الطلب #{$order->code}".($reason ? " — $reason" : ''),
                    };
                    $put(array_filter(['type' => 'order', 'id' => $order->id, 'status' => $status, 'prep' => $prep, 'reason' => $reason],
                        fn ($v) => $v !== null), $label, $to === OrderStatus::Cancelled);
                    break;
            }
        }

        return [array_values($actions), array_values(array_unique($notes))];
    }

    private function findOrder(Store $store, string $code): ?Order
    {
        $code = trim(self::digits($code), " #\t");
        if ($code === '') {
            return null;
        }

        return $store->orders()->active()
            ->where(fn ($q) => $q->where('code', $code)->orWhere('code', 'like', $code.'-%')->orWhere('id', ctype_digit($code) ? (int) $code : 0))
            ->latest()->first();
    }

    /** «5» أو «17» أو «5:30» → HH:MM (الأرقام الصغيرة اللي فاتت = العشية) */
    private static function cleanUntil(mixed $v): ?string
    {
        if (! is_string($v) && ! is_int($v)) {
            return null;
        }
        if (! preg_match('/^(\d{1,2})(?::(\d{2}))?$/', self::digits(trim((string) $v)), $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) ($m[2] ?? 0);
        if ($h > 23 || $min > 59) {
            return null;
        }
        $now = Carbon::now(LocalDay::timezone());
        if ($h >= 1 && $h <= 11 && ($h < $now->hour || ($h === $now->hour && $min <= $now->minute)) && $h + 12 > $now->hour) {
            $h += 12;
        }

        return sprintf('%02d:%02d', $h, $min);
    }

    // ================= الذكاء الاصطناعي =================

    /** @return array{0: array, 1: ?string} */
    private function aiIntents(Store $store, string $text): array
    {
        $sections = $store->sections()->get(['id', 'name', 'is_available', 'paused_until']);
        $products = $store->products()->where('is_visible', true)->orderBy('menu_section_id')->limit(300)
            ->get(['id', 'name', 'menu_section_id', 'is_available']);
        $orders = $store->orders()->active()->latest()->limit(40)->get(['id', 'code', 'status']);

        $catalog = "SECTIONS (id | name | available):\n"
            .$sections->map(fn ($s) => "{$s->id} | {$s->name} | ".($s->isOrderable() ? 'yes' : 'no'))->implode("\n")
            ."\n\nPRODUCTS (id | name | section_id | available):\n"
            .$products->map(fn ($p) => "{$p->id} | {$p->name} | {$p->menu_section_id} | ".($p->is_available ? 'yes' : 'no'))->implode("\n")
            ."\n\nACTIVE ORDERS (code | status):\n"
            .$orders->map(fn ($o) => "{$o->code} | {$o->status->value}")->implode("\n")
            ."\n\nSTORE: ".($store->isAcceptingOrders() ? 'open' : 'closed')
            .' · local time '.Carbon::now(LocalDay::timezone())->format('H:i');

        $system = <<<'TXT'
You turn a restaurant/shop staff member's spoken command (Libyan Arabic dialect, may contain speech-recognition mistakes) into JSON actions for their store app.
Reply with ONE JSON object only, no prose:
{"actions":[...], "unclear": null | "the part you could not map (Arabic, short)"}

Allowed actions (use ONLY ids/codes from the catalog the user message gives):
- {"type":"product","id":<product id>,"available":true|false}
- {"type":"section","id":<section id>,"available":true|false,"until":"HH:MM" (optional, 24h, only when pausing until a time)}
- {"type":"all_sections","available":true|false,"except":[<section ids that get the opposite>]}
- {"type":"all_products","available":true|false,"except":[<product ids>]}
- {"type":"store","open":true|false}            (open/close the whole restaurant)
- {"type":"pickup","enabled":true|false}        (in-store pickup on/off)
- {"type":"order","code":"<order code>","status":"preparing"|"ready"|"cancelled","prep":<minutes, optional>,"reason":"<Arabic, optional>"}

Meaning hints (Libyan): خلص/خلصت/تمت/كمل/نفد/ماعادش/مش متوفر/وقّف = not available. رجع/توفر/متوفر/موجود/شغّل = available.
سكّر/اقفل/اغلق المطعم = store closed. افتح المطعم = open. "الطلب 43 جاهز" = order ready. "اقبل/قبلت/نحضّرو الطلب" = preparing. "ارفض/الغي الطلب" = cancelled.
"كل الأقسام متوفرة ما عدا المعجنات" = all_sections available true, except [id of معجنات].
If a word names a section, prefer the section; if it names one or more products (e.g. "الشاورما" matching "شاورما لحم" and "شاورما دجاج" with no section called شاورما), list each product.
Never invent ids. If nothing matches, return empty actions and explain in "unclear".
TXT;

        $user = $catalog."\n\nCOMMAND: ".$text;
        $raw = $this->callAi($system, $user);

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false) {
            throw new \RuntimeException('no json');
        }
        $json = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($json) || ! isset($json['actions']) || ! is_array($json['actions'])) {
            throw new \RuntimeException('bad json');
        }

        $unclear = is_string($json['unclear'] ?? null) && trim($json['unclear']) !== '' ? mb_substr(trim($json['unclear']), 0, 200) : null;

        return [array_values(array_filter($json['actions'], 'is_array')), $unclear];
    }

    private function callAi(string $system, string $user): string
    {
        $cfg = config('services.voice_ai');
        $timeout = max(3, (int) ($cfg['timeout'] ?? 12));

        if (($cfg['provider'] ?? 'anthropic') === 'anthropic') {
            $res = Http::timeout($timeout)->withHeaders([
                'x-api-key' => $cfg['key'],
                'anthropic-version' => '2023-06-01',
            ])->post($cfg['url'] ?: 'https://api.anthropic.com/v1/messages', [
                'model' => $cfg['model'] ?: 'claude-haiku-4-5',
                'max_tokens' => 800,
                'temperature' => 0,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ])->throw();

            return (string) collect($res->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        }

        $res = Http::timeout($timeout)->withToken($cfg['key'])
            ->post($cfg['url'] ?: 'https://api.openai.com/v1/chat/completions', [
                'model' => $cfg['model'] ?: 'gpt-4o-mini',
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            ])->throw();

        return (string) $res->json('choices.0.message.content', '');
    }

    // ================= القواعد (بدون ذكاء اصطناعي) =================

    private const OFF = ['ماعادش', 'ما عادش', 'مش متوفر', 'مش متوفره', 'غير متوفر', 'غير متوفره', 'مش موجود', 'مش موجوده', 'مافيش', 'ما فيش',
        'خلصت', 'خلص', 'خلاص خلص', 'تمت', 'تم', 'كملت', 'كمل', 'نفدت', 'نفد', 'نفذت', 'نفذ', 'وقف', 'اوقف', 'وقفو', 'طفي', 'اطفي', 'سكر', 'اقفل', 'قفل', 'اغلق'];

    private const ON = ['رجعت', 'رجع', 'توفرت', 'توفر', 'متوفرات', 'متوفره', 'متوفر', 'موجوده', 'موجود', 'شغل', 'شغلو', 'فعل', 'افتح', 'نزلت', 'نزل', 'وصلت', 'وصل', 'مفتوحه', 'شغاله'];

    private const FILLER = ['قسم', 'اقسام', 'صنف', 'اصناف', 'منتج', 'حاليا', 'توا', 'اليوم', 'خلاص', 'يا', 'جماعه', 'من', 'فضلك', 'لو', 'سمحت', 'ال', 'هو', 'هي', 'كله', 'كلها', 'عندنا', 'عندي'];

    /** @return array{0: array, 1: ?string} */
    private function ruleIntents(Store $store, string $text): array
    {
        $t = self::norm($text);
        $sections = $store->sections()->get(['id', 'name']);
        $products = $store->products()->where('is_visible', true)->limit(500)->get(['id', 'name', 'menu_section_id']);

        $intents = [];
        $missed = [];

        // «كل الأقسام متوفرة ما عدا ...» — قبل التقسيم لأن «ما عدا» ممكن فيها فواصل وواوات
        if (preg_match('/^(?:كل|جميع)\s+(?:ال)?(اقسام|اصناف|منتجات|اكل|شي)\s+(.+?)\s+(?:ما عدا|ماعدا|الا|غير|باستثناء|بس)\s+(.+)$/u', $t, $m)) {
            $available = ! $this->isOff($m[2]);
            $ids = ['s' => [], 'p' => []];
            foreach (preg_split('/\s*(?:،|,)\s*|\s+و(?=ال)/u', $m[3]) as $name) {
                $name = $this->stripStateWords(trim($name));
                if ($name === '') {
                    continue;
                }
                [$kind, $found] = $this->match($name, $sections, $products);
                $kind ? $ids[$kind] = [...$ids[$kind], ...$found] : $missed[] = $name;
            }
            $intents[] = ['type' => 'all_sections', 'available' => $available, 'except' => $ids['s']];
            foreach ($ids['p'] as $pid) {
                $intents[] = ['type' => 'product', 'id' => $pid, 'available' => ! $available];
            }

            return [$intents, $missed ? 'ما فهمناش: '.implode('، ', $missed) : null];
        }
        if (preg_match('/^(?:كل|جميع)\s+(?:ال)?(اقسام|اصناف|منتجات|اكل|شي)\s+(.+)$/u', $t, $m)) {
            return [[['type' => $m[1] === 'اصناف' || $m[1] === 'منتجات' ? 'all_products' : 'all_sections',
                'available' => ! $this->isOff($m[2]), 'except' => []]], null];
        }

        foreach (preg_split('/\s*(?:،|,|\.(?!\d)|؛)\s*|\s+و(?:كمان\s+)?(?=ال|طلب|سكر|اقفل|افتح|وقف|\d)/u', $t) as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }
            $in = $this->clause($clause, $sections, $products);
            $in ? array_push($intents, ...$in) : $missed[] = $clause;
        }

        return [$intents, $missed ? 'ما فهمناش: «'.implode('»، «', $missed).'»' : null];
    }

    private function stripStateWords(string $s): string
    {
        foreach ([...self::OFF_NEG(), ...self::OFF, ...self::ON] as $w) {
            $s = preg_replace('/(^|\s)'.preg_quote($w, '/').'(?=$|\s)/u', ' ', $s);
        }

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function OFF_NEG(): array
    {
        return ['مش متوفر', 'غير متوفر', 'مش موجود', 'ماعادش', 'ما عادش', 'مافيش', 'ما فيش'];
    }

    private function isOff(string $c): bool
    {
        if ($this->hasAny($c, self::OFF_NEG())) {
            return true;
        }
        foreach (self::OFF as $w) {
            if (preg_match('/(^|\s)'.preg_quote($w, '/').'($|\s)/u', $c)) {
                return true;
            }
        }

        return false;
    }

    private function hasAny(string $c, array $words): bool
    {
        foreach ($words as $w) {
            if (preg_match('/(^|\s)'.preg_quote($w, '/').'($|\s)/u', $c)) {
                return true;
            }
        }

        return false;
    }

    private function clause(string $c, $sections, $products): array
    {
        $storeWord = '(?:ال)?(?:مطعم|محل|متجر|كافيه|مخبز|مخبزه)';

        // الطلبات: «الطلب 43 جاهز» · «43 جاهز» · «اقبل الطلب 43 في 20 دقيقه» · «ارفض الطلب 43»
        if (preg_match('/(?:(?:ال)?(?:طلب|طلبيه|اوردر|رقم)\s*(?:رقم\s*)?#?\s*(\d+))|(?:^(\d+)\s)|(?:\s(\d+)$)/u', $c, $m)
            && preg_match('/جاهز|جهز|تجهز|اقبل|قبل|ابدا|بدينا|حضر|نحضر|ارفض|رفض|الغي|الغاء|كنسل|(?:ال)?طلب/u', $c)) {
            $code = $m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? '');
            $status = match (true) {
                (bool) preg_match('/ارفض|رفض|الغي|الغاء|كنسل/u', $c) => 'cancelled',
                (bool) preg_match('/جاهز|جهز|تجهز|خلص|كمل/u', $c) => 'ready',
                (bool) preg_match('/اقبل|قبل|ابدا|بدينا|حضر|نحضر/u', $c) => 'preparing',
                default => null,
            };
            if ($status) {
                $prep = preg_match('/(?:في|خلال|بعد)\s+(\d+)\s*(?:دقيقه|دقايق|دقائق|د)/u', $c, $pm) ? (int) $pm[1] : null;
                $reason = $status === 'cancelled' && preg_match('/(?:لان|بسبب|السبب)\s+(.+)$/u', $c, $rm) ? $rm[1] : null;

                return [array_filter(['type' => 'order', 'code' => $code, 'status' => $status, 'prep' => $prep, 'reason' => $reason], fn ($v) => $v !== null)];
            }
        }

        // الاستلام من المطعم
        if (preg_match('/(?:ال)?استلام/u', $c)) {
            return [['type' => 'pickup', 'enabled' => ! $this->isOff($c)]];
        }

        // المتجر كامل: «سكّر المطعم» · «افتح المحل» · «سكر» وحدها
        if (preg_match('/^(?:سكر|سكرو|اقفل|قفل|اغلق|سكير)(?:\s+'.$storeWord.')?(?:\s+.*)?$/u', $c) && (preg_match('/'.$storeWord.'/u', $c) || ! str_contains($c, ' '))) {
            return [['type' => 'store', 'open' => false]];
        }
        if (preg_match('/^(?:افتح|افتحو|فتح)(?:\s+'.$storeWord.')?$/u', $c) || preg_match('/^'.$storeWord.'\s+(?:مفتوح|مسكر|مقفول|مغلق)/u', $c)) {
            return [['type' => 'store', 'open' => ! preg_match('/مسكر|مقفول|مغلق/u', $c)]];
        }

        // التوفّر: «الشاورما خلصت» · «وقف قسم المعجنات لين الساعه 5» · «البيتزا رجعت»
        $until = preg_match('/(?:لين|لغايه|لغايت|حتي|الي|للساعه)\s+(?:الساعه\s+)?(\d{1,2}(?::\d{2})?)/u', $c, $um) ? $um[1] : null;
        $subject = preg_replace('/(?:لين|لغايه|لغايت|حتي|الي|للساعه)\s+(?:الساعه\s+)?\d{1,2}(?::\d{2})?.*/u', '', $c);
        $off = $this->isOff($subject);
        $on = ! $off && $this->hasAny($subject, self::ON);
        if (! $off && ! $on) {
            return [];
        }
        $subject = $this->stripStateWords($subject);
        if ($subject === '') {
            return [];
        }

        [$kind, $ids] = $this->match($subject, $sections, $products);
        if (! $kind) {
            return [];
        }

        return array_map(fn ($id) => $kind === 's'
            ? ['type' => 'section', 'id' => $id, 'available' => $on, 'until' => $until]
            : ['type' => 'product', 'id' => $id, 'available' => $on], $ids);
    }

    /** @return array{0: ?string, 1: int[]}  's' قسم · 'p' منتجات */
    private function match(string $phrase, $sections, $products): array
    {
        $words = self::words($phrase);
        if (! $words) {
            return [null, []];
        }

        // القسم: كلماته نفس الكلمات (أو كلها موجودة)
        $best = null;
        foreach ($sections as $s) {
            $sw = self::words($s->name);
            if ($sw && self::covers($sw, $words) && self::covers($words, $sw)) {
                return ['s', [$s->id]];
            }
            if ($sw && self::covers($sw, $words)) {
                $best ??= $s;
            }
        }

        $ps = $products->filter(fn ($p) => self::covers(self::words($p->name), $words))->pluck('id')->take(30)->all();
        if ($ps) {
            return ['p', $ps];
        }

        return $best ? ['s', [$best->id]] : [null, []];
    }

    /** كل كلمة من $needles موجودة في $hay (تطابق أو قريبة — أخطاء التعرّف على الصوت) */
    private static function covers(array $hay, array $needles): bool
    {
        foreach ($needles as $n) {
            $hit = false;
            foreach ($hay as $h) {
                if ($h === $n || (mb_strlen($n) >= 4 && (str_starts_with($h, $n) || str_starts_with($n, $h) && mb_strlen($h) >= 4))
                    || (mb_strlen($n) >= 4 && levenshtein($h, $n) <= 2)) {
                    $hit = true;
                    break;
                }
            }
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    private static function words(string $s): array
    {
        $out = [];
        foreach (explode(' ', self::norm($s)) as $w) {
            $w = preg_replace('/^(?:وال|بال|لل|ال)(?=\X{2,})/u', '', $w);
            if ($w !== '' && ! in_array($w, self::FILLER, true)) {
                $out[] = $w;
            }
        }

        return $out;
    }

    public static function norm(string $s): string
    {
        $s = self::digits($s);
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s); // تشكيل وتطويل
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\p{L}\p{N}:#\s،,.؛]/u', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    private static function digits(string $s): string
    {
        return strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }
}
