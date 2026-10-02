<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminAlerts;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * مركز الطوارئ: مفاتيح توقف جزء من المنصة فوراً لو اكتشفنا ثغرة أو هجوم.
 *
 * كل مفتاح يوقف حاجة وحدة بس، والباقي يكمّل يخدم — نقفلو أصغر جزء يوقف الضرر.
 * الإيقاف يصير في السيرفر (EmergencyGate)، فحتى النسخ القديمة من التطبيقات
 * والسكربتات اللي تكلم الـ API مباشرة تتوقف. التطبيقات الجديدة تقرا الحالة من
 * /app/content وتوري شاشة واضحة بدل أخطاء.
 *
 * يتشغّل من اللوحة («الإعدادات ← مركز الطوارئ») أو من السيرفر لو اللوحة نفسها
 * مش آمنة: php artisan emergency on payments
 */
class Emergency
{
    /** المفتاح => [الاسم, شن يوقف بالضبط] */
    public const SWITCHES = [
        'payments' => ['إيقاف الدفع الإلكتروني',
            'يوقف بدء أي دفع جديد بالبطاقة أو البوابات (checkout ورموز التحقق). الطلبات النقدية تكمّل عادي. ردود البوابة على مدفوعات بدت قبل الإيقاف تتسجّل عادي.'],
        'wallet' => ['تجميد المحافظ',
            'يوقف الدفع من المحفظة، شحن الكروت، وتحويل النقاط لرصيد. الأرصدة ما تتلمسش.'],
        'orders' => ['إيقاف الطلبات الجديدة',
            'الزبون ما يقدرش يبعت طلب جديد (ولا «اطلب مرة ثانية»). الطلبات اللي شغّالة تكمّل مع المتجر والسائق.'],
        'auth' => ['إيقاف الدخول والحسابات الجديدة',
            'يوقف إرسال رموز الدخول وتسجيل الدخول في تطبيقات الزبون والمتجر والسائق. اللي داخل أصلاً يكمّل (إلا لو أنهيت الجلسات).'],
        'uploads' => ['إيقاف رفع الملفات',
            'يرفض أي طلب فيه ملف: صور الأصناف، صور الدعم، الصورة الشخصية...'],
        'app_customer' => ['قفل تطبيق الزبون (وموقع الطلب)', 'التطبيق يوري شاشة «متوقف مؤقتاً» وكل طلباته ترجع مرفوضة.'],
        'app_store' => ['قفل تطبيق المتجر (ولوحة المتجر على الموقع)', 'المتاجر ما تقدرش تستلم أو تعدّل حتى شي.'],
        'app_driver' => ['قفل تطبيق السائق', 'السائقين ما يقدروش يقبلو أو يحدّثو الطلبات.'],
        'app_admin' => ['قفل تطبيق الإدارة (الهاتف)', 'لوحة التحكم على الموقع تبقى شغّالة — باش تقدر ترجّع الأمور.'],
    ];

    public const APPS = ['customer', 'store', 'driver', 'admin'];

    public const DEFAULT_MESSAGE = 'الخدمة متوقفة مؤقتاً للصيانة. نرجعو في أقرب وقت، وشكراً على صبركم.';

    public static function on(string $switch): bool
    {
        return array_key_exists($switch, self::SWITCHES)
            && Setting::get("emergency.$switch", '0') === '1';
    }

    /** @return list<string> المفاتيح الشغّالة توّا */
    public static function active(): array
    {
        return array_values(array_filter(array_keys(self::SWITCHES), fn ($s) => self::on($s)));
    }

    public static function message(): string
    {
        $m = trim((string) Setting::get('emergency.message', ''));

        return $m !== '' ? $m : self::DEFAULT_MESSAGE;
    }

    /**
     * تشغيل أو إيقاف مفاتيح. يتسجّل في سجل النشاط، ويوصل تنبيه لكل الإدارة.
     *
     * @param  list<string>  $switches
     * @return list<string> اللي تغيّر فعلاً
     */
    public static function set(array $switches, bool $on, ?User $by = null, string $via = 'اللوحة'): array
    {
        $changed = [];
        foreach ($switches as $s) {
            if (! array_key_exists($s, self::SWITCHES) || self::on($s) === $on) {
                continue;
            }
            Setting::put("emergency.$s", $on ? '1' : '0');
            $changed[] = $s;
        }

        if (! $changed) {
            return [];
        }

        Setting::put('emergency.updated_at', now()->toIso8601String());
        Setting::put('emergency.updated_by', $by ? "{$by->name} (#{$by->id})" : $via);

        $names = implode('، ', array_map(fn ($s) => self::SWITCHES[$s][0], $changed));
        $who = $by?->name ?? $via;
        $title = $on ? '🚨 تفعيل طوارئ' : '✅ رفع طوارئ';
        $body = ($on ? 'اتشغّل: ' : 'اتوقف: ')."$names — بواسطة $who";

        Activity::record($on ? 'emergency.on' : 'emergency.off', "$title: $names", null,
            ['switches' => $changed, 'via' => $via], $by);

        try {
            // مفتاح مختلف كل مرة باش ما يتدمجش مع تنبيه قبله
            AdminAlerts::send($title, $body, '/admin/emergency-center', $on ? 'danger' : 'success',
                'emergency:'.now()->timestamp.':'.implode(',', $changed), 'orders.view', 'emergency');
        } catch (\Throwable) {
            // التنبيه ما يمنعش الإيقاف
        }

        return $changed;
    }

    public static function setMessage(string $message): void
    {
        Setting::put('emergency.message', trim($message));
    }

    // ===== تحديث إجباري =====

    /** أقل رقم بناء (build number) مسموح لكل تطبيق — 0 = بدون */
    public static function minBuild(string $app): int
    {
        return max(0, (int) Setting::get("emergency.min_build.$app", 0));
    }

    public static function updateUrls(string $app): array
    {
        return [
            'android' => (string) Setting::get("emergency.update_android.$app", ''),
            'ios' => (string) Setting::get("emergency.update_ios.$app", ''),
        ];
    }

    /** للتطبيق (/app/content): هل هو مقفول، والرسالة، والتحديث الإجباري */
    public static function forApp(string $app): array
    {
        $blocked = array_values(array_filter(
            ['payments', 'wallet', 'orders', 'auth', 'uploads'],
            fn ($s) => self::on($s)
        ));

        return [
            'locked' => self::on("app_$app"),
            'message' => self::message(),
            // المفاتيح الجزئية — التطبيق يقدر يوري شريط تنبيه (مثلاً «الدفع الإلكتروني متوقف»)
            'blocked' => $blocked,
            'min_build' => self::minBuild($app),
            'update_url' => self::updateUrls($app),
        ];
    }

    // ===== إنهاء الجلسات =====

    /**
     * يمسح توكنات الدخول — اللي عنده الدور هذا يطلع من التطبيق ويلزمه يدخل من جديد.
     *
     * admin = توكنات تطبيق الإدارة (الهاتف) بس. لوحة الموقع تتقفل بـ disableOtherAdmins().
     *
     * @return int عدد التوكنات اللي انمسحت
     */
    public static function revokeTokens(string $role, ?User $by = null): int
    {
        $q = PersonalAccessToken::query()->where('tokenable_type', (new User)->getMorphClass());

        if ($role === 'admin') {
            $q->where('name', 'like', 'admin-app:%');
        } elseif ($role !== 'all') {
            $ids = User::query()->get(['id', 'role', 'roles'])
                ->filter(fn (User $u) => $u->hasRole($role))
                ->pluck('id');
            $q->whereIn('tokenable_id', $ids)->where('name', 'not like', 'admin-app:%');
        }

        $n = $q->delete();

        Activity::record('emergency.logout', "إنهاء جلسات ($role): $n جلسة", null, ['role' => $role, 'count' => $n], $by);

        return $n;
    }

    /**
     * يوقف كل حسابات الإدارة ما عدا [$keep] — يطلعو من لوحة الموقع فوراً (الفحص مع كل طلب).
     * القائمة تتحفظ باش نرجّعوهم بضغطة.
     */
    public static function disableOtherAdmins(User $keep): int
    {
        $ids = User::query()->where('is_active', true)->whereKeyNot($keep->getKey())->get()
            ->filter(fn (User $u) => $u->hasRole(UserRole::Admin))
            ->pluck('id')->all();

        if (! $ids) {
            return 0;
        }

        DB::transaction(function () use ($ids) {
            User::whereIn('id', $ids)->update(['is_active' => false]);
            $prev = json_decode((string) Setting::get('emergency.disabled_admins', '[]'), true) ?: [];
            Setting::put('emergency.disabled_admins', json_encode(array_values(array_unique([...$prev, ...$ids]))));
        });

        // توكنات تطبيق الإدارة متاعهم كمان
        PersonalAccessToken::whereIn('tokenable_id', $ids)->where('name', 'like', 'admin-app:%')->delete();

        Activity::record('emergency.admins_off', 'إيقاف حسابات الإدارة الأخرى: '.count($ids), null, ['ids' => $ids], $keep);

        return count($ids);
    }

    /** يرجّع الحسابات اللي وقفها disableOtherAdmins() */
    public static function restoreAdmins(?User $by = null): int
    {
        $ids = json_decode((string) Setting::get('emergency.disabled_admins', '[]'), true) ?: [];
        if (! $ids) {
            return 0;
        }
        $n = User::whereIn('id', $ids)->update(['is_active' => true]);
        Setting::put('emergency.disabled_admins', '[]');
        Activity::record('emergency.admins_on', "إرجاع حسابات الإدارة: $n", null, ['ids' => $ids], $by);

        return $n;
    }

    public static function disabledAdmins(): array
    {
        return json_decode((string) Setting::get('emergency.disabled_admins', '[]'), true) ?: [];
    }
}
