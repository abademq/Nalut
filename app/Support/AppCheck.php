<?php

namespace App\Support;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Firebase App Check — يتأكد إن الطلب جاي من تطبيقنا الأصلي (Play Integrity على أندرويد،
 * App Attest على آيفون، reCAPTCHA Enterprise على نسخة الويب) مش من سكربت.
 *
 * الأوضاع (من «إعدادات التشغيل ← الحماية»):
 *   off      معطّل
 *   monitor  يتفحص ويعدّ بس (ما يمنعش) — نشوفو النسبة في «حالة السيرفر» قبل ما نفعّلو المنع
 *   enforce  يرفض أي طلب حساس بدون رمز صالح
 */
class AppCheck
{
    public const JWKS = 'https://firebaseappcheck.googleapis.com/v1/jwks';

    public static function mode(): string
    {
        if (blank(config('services.appcheck.project_number'))) {
            return 'off';
        }

        return (string) (rescue(fn () => Options::get('security.app_check'), 'off', false) ?: 'off');
    }

    /** @return 'ok'|'missing'|'invalid' */
    public static function check(?string $token): string
    {
        if (blank($token)) {
            return 'missing';
        }

        try {
            $number = (string) config('services.appcheck.project_number');
            $keys = Cache::remember('appcheck:jwks', now()->addHours(6),
                fn () => Http::timeout(8)->get(self::JWKS)->throw()->json());

            JWT::$leeway = 30;
            $claims = JWT::decode($token, JWK::parseKeySet($keys, 'RS256'));

            $aud = (array) ($claims->aud ?? []);
            $okAud = in_array("projects/$number", $aud, true)
                || (filled($pid = config('services.fcm.project_id')) && in_array("projects/$pid", $aud, true));

            return ($claims->iss ?? '') === "https://firebaseappcheck.googleapis.com/$number" && $okAud ? 'ok' : 'invalid';
        } catch (ExpiredException|\Firebase\JWT\SignatureInvalidException|\UnexpectedValueException|\DomainException) {
            return 'invalid';
        } catch (\Throwable) {
            // مفاتيح جوجل مش واصلة — ما نقفلوش التطبيق على الناس
            return 'ok';
        }
    }

    /** عدّاد يومي للصفحة */
    public static function count(string $result): void
    {
        $key = 'appcheck:'.date('Ymd');
        $c = Cache::get($key, ['ok' => 0, 'missing' => 0, 'invalid' => 0]);
        $c[$result] = ($c[$result] ?? 0) + 1;
        Cache::put($key, $c, now()->addDays(3));
    }

    public static function stats(): array
    {
        return Cache::get('appcheck:'.date('Ymd'), ['ok' => 0, 'missing' => 0, 'invalid' => 0]);
    }
}
