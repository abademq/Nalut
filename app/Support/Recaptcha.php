<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Google reCAPTCHA v2 — التحقق من جهة السيرفر */
class Recaptcha
{
    public static function siteKey(): ?string
    {
        return config('services.recaptcha.site_key') ?: null;
    }

    public static function configured(): bool
    {
        return filled(config('services.recaptcha.site_key')) && filled(config('services.recaptcha.secret_key'));
    }

    public static function adminEnabled(): bool
    {
        return self::configured() && (bool) rescue(fn () => Options::get('security.recaptcha_admin'), true, false);
    }

    public static function verify(?string $token, ?string $ip = null): bool
    {
        if (blank($token)) {
            return false;
        }

        try {
            $r = Http::asForm()->timeout(8)->post('https://www.google.com/recaptcha/api/siteverify', array_filter([
                'secret' => config('services.recaptcha.secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));

            if (! $r->successful()) {
                throw new \RuntimeException('HTTP '.$r->status());
            }

            return (bool) $r->json('success');
        } catch (\Throwable $e) {
            // جوجل مش واصلة (نت السيرفر) — ما نقفلوش الإدارة برّا، نسجّلو بس
            Log::warning('recaptcha unreachable: '.$e->getMessage());

            return true;
        }
    }
}
