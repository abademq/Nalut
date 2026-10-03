<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * التحقق بخطوتين لحسابات الإدارة: بعد كلمة المرور، رمز على هاتف الموظف
 * (نفس قناة رموز التحقق: واتساب أو SMS). يتفعّل من «إعدادات التشغيل ← الحماية».
 *
 * - لوحة التحكم: صفحة /admin-2fa بعد الدخول، ومع خيار «هذا جهازي» (كوكي مشفّر لمدة محددة).
 * - تطبيق الإدارة: الدخول يرجّع «challenge» بدل التوكن، والتوكن يطلع بعد الرمز.
 */
class AdminTwoFactor
{
    public const SESSION_KEY = 'admin_2fa_passed';

    public const COOKIE = 'admin_2fa_trust';

    public static function enabled(): bool
    {
        return (bool) Options::get('security.admin_2fa');
    }

    public static function phoneOf(User $user): ?string
    {
        $p = preg_replace('/\D/', '', (string) $user->phone);
        if (str_starts_with($p, '218')) {
            $p = '0'.substr($p, 3);
        }

        return preg_match('/^09[1-6]\d{7}$/', $p) ? $p : null;
    }

    public static function masked(User $user): string
    {
        $p = self::phoneOf($user) ?? '';

        return $p ? substr($p, 0, 3).'•••••'.substr($p, -2) : '—';
    }

    /** يبعت رمز — يرجّع القناة (whatsapp | sms | test) */
    public static function send(User $user, ?string $ip = null): array
    {
        $phone = self::phoneOf($user);
        if (! $phone) {
            throw ValidationException::withMessages(['code' => 'حسابك ما فيهش رقم هاتف ليبي صحيح — كلّم المدير يضيفه قبل الدخول.']);
        }

        $r = app(OtpService::class)->request($phone, $ip);
        Activity::record('admin_2fa.sent', "رمز دخول الإدارة: {$user->name}", $user, ['channel' => $r['channel']], $user);

        return $r;
    }

    public static function verify(User $user, string $code): void
    {
        $phone = self::phoneOf($user);
        if (! $phone) {
            throw ValidationException::withMessages(['code' => 'حسابك ما فيهش رقم هاتف صحيح.']);
        }
        app(OtpService::class)->verify($phone, $code);
        Activity::record('admin_2fa.passed', "تحقق بخطوتين: {$user->name}", $user, [], $user);
    }

    // ===== لوحة التحكم: الجهاز الموثوق =====

    /** بصمة الحساب: تبديل كلمة المرور يلغي كل الأجهزة الموثوقة */
    private static function fingerprint(User $user): string
    {
        return substr(hash('sha256', $user->id.'|'.(string) $user->password), 0, 24);
    }

    public static function trust(User $user): void
    {
        $days = (int) Options::get('security.admin_2fa_trust_days');
        if ($days <= 0) {
            return;
        }
        Cookie::queue(self::COOKIE, json_encode(['u' => $user->id, 'f' => self::fingerprint($user), 'e' => now()->addDays($days)->timestamp]),
            $days * 24 * 60, null, null, request()->isSecure(), true, false, 'lax');
    }

    public static function isTrusted(User $user): bool
    {
        if ((int) Options::get('security.admin_2fa_trust_days') <= 0) {
            return false;
        }
        $data = json_decode((string) request()->cookie(self::COOKIE), true);

        return is_array($data)
            && (int) ($data['u'] ?? 0) === $user->id
            && hash_equals(self::fingerprint($user), (string) ($data['f'] ?? ''))
            && (int) ($data['e'] ?? 0) > now()->timestamp;
    }

    public static function passedInSession(User $user): bool
    {
        return session(self::SESSION_KEY) === $user->id || self::isTrusted($user);
    }

    // ===== تطبيق الإدارة: challenge مشفّر =====

    public static function challengeFor(User $user): string
    {
        return Crypt::encryptString(json_encode(['u' => $user->id, 'e' => now()->addMinutes(10)->timestamp]));
    }

    public static function userFromChallenge(string $challenge): User
    {
        try {
            $data = json_decode(Crypt::decryptString($challenge), true);
        } catch (\Throwable) {
            $data = null;
        }
        $user = is_array($data) && (int) ($data['e'] ?? 0) > now()->timestamp ? User::find((int) ($data['u'] ?? 0)) : null;

        if (! $user || ! $user->is_active || ! $user->hasRole(UserRole::Admin)) {
            throw ValidationException::withMessages(['code' => 'انتهت المهلة — ادخل من جديد.']);
        }

        return $user;
    }
}
