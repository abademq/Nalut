<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * إصدار رموز التحقق والتحقق منها.
 *
 * - الرمز يتخزّن مشفّر (HMAC) — حتى لو تسرّبت قاعدة البيانات ما تنقراش الرموز.
 * - حدود: مهلة بين الطلبات، حد بالساعة لكل رقم ولكل IP، وعدد محاولات لكل رمز.
 * - القناة حسب OTP_DRIVER. لاحقاً: واتساب أولاً ثم SMS كاحتياطي.
 */
class OtpService
{
    /**
     * @return array{channel: string, code: string, expires_in: int, resend_after: int}
     */
    public function request(string $phone, ?string $ip = null, ?string $appHash = null): array
    {
        $cfg = config('otp');

        $this->guardLimits($phone, $ip, $cfg);

        $test = $cfg['test_numbers'][$phone] ?? null;

        if ($test !== null) {
            [$code, $channel] = [$test, 'test'];
        } else {
            [$code, $channel] = $this->deliver($phone, $cfg, $appHash);
        }

        // رمز جديد يلغي أي رمز سابق لنفس الرقم
        DB::table('otp_codes')
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now(), 'updated_at' => now()]);

        DB::table('otp_codes')->insert([
            'phone'      => $phone,
            'code'       => $this->hash($code),
            'channel'    => $channel,
            'attempts'   => 0,
            'expires_at' => now()->addSeconds($cfg['ttl']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($ip) {
            RateLimiter::hit('otp-ip:'.$ip, 3600);
        }

        return [
            'channel'      => $channel,
            'code'         => $code,
            'expires_in'   => $cfg['ttl'],
            'resend_after' => $cfg['resend_cooldown'],
        ];
    }

    /**
     * يتحقق من الرمز ويستهلكه. يرمي ValidationException لو غلط.
     */
    public function verify(string $phone, string $input): void
    {
        $otp = DB::table('otp_codes')
            ->where('phone', $phone)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $otp) {
            throw ValidationException::withMessages(['code' => 'الرمز منتهي. اطلب رمز جديد.']);
        }

        $max = (int) config('otp.max_attempts');

        if (! hash_equals($otp->code, $this->hash(trim($input)))) {
            $attempts = $otp->attempts + 1;

            DB::table('otp_codes')->where('id', $otp->id)->update([
                'attempts'    => $attempts,
                // بعد آخر محاولة يُلغى الرمز نهائياً
                'consumed_at' => $attempts >= $max ? now() : null,
                'updated_at'  => now(),
            ]);

            $left = $max - $attempts;

            throw ValidationException::withMessages([
                'code' => $left > 0
                    ? "الرمز غير صحيح. باقيلك {$left} محاولات."
                    : 'تجاوزت عدد المحاولات. اطلب رمز جديد.',
            ]);
        }

        DB::table('otp_codes')->where('id', $otp->id)->update([
            'consumed_at' => now(),
            'updated_at'  => now(),
        ]);
    }

    /** @return array{0: string, 1: string} [code, channel] */
    private function deliver(string $phone, array $cfg, ?string $appHash = null): array
    {
        return match ($cfg['driver']) {
            'resala' => $this->viaResala($phone, $cfg, $appHash),
            'log'    => $this->viaLog($phone, $cfg),
            default  => throw new \InvalidArgumentException("OTP_DRIVER غير معروف: {$cfg['driver']}"),
        };
    }

    private function viaResala(string $phone, array $cfg, ?string $appHash = null): array
    {
        try {
            $result = ResalaClient::fromConfig()->sendPin(
                $this->international($phone),
                $cfg['length'],
                $cfg['service_name'],
                $appHash,
            );
        } catch (ResalaException $e) {
            Log::log($e->insufficientCredit ? 'critical' : 'error', 'OTP send failed: '.$e->getMessage(), ['phone' => $phone]);

            // ما نكشفوش تفاصيل المزوّد للمستخدم
            throw new HttpException(503, 'تعذّر إرسال رمز التحقق حالياً. حاول بعد شوية.');
        }

        return [$result['pin'], 'sms'];
    }

    private function viaLog(string $phone, array $cfg): array
    {
        $code = str_pad((string) random_int(0, 10 ** $cfg['length'] - 1), $cfg['length'], '0', STR_PAD_LEFT);

        Log::info("OTP for {$phone}: {$code}");

        return [$code, 'log'];
    }

    private function guardLimits(string $phone, ?string $ip, array $cfg): void
    {
        $last = DB::table('otp_codes')->where('phone', $phone)->max('created_at');

        if ($last) {
            $wait = $cfg['resend_cooldown'] - (int) now()->diffInSeconds($last, true);

            if ($wait > 0) {
                throw ValidationException::withMessages([
                    'phone' => "استنى {$wait} ثانية قبل ما تطلب رمز جديد.",
                ]);
            }
        }

        $hourly = DB::table('otp_codes')
            ->where('phone', $phone)
            ->where('created_at', '>', now()->subHour())
            ->count();

        if ($hourly >= $cfg['max_per_hour']) {
            throw ValidationException::withMessages([
                'phone' => 'طلبت رموز كثيرة. حاول بعد ساعة.',
            ]);
        }

        if ($ip && RateLimiter::tooManyAttempts('otp-ip:'.$ip, $cfg['max_per_ip_hour'])) {
            throw ValidationException::withMessages([
                'phone' => 'طلبات كثيرة من هذا الجهاز. حاول بعد شوية.',
            ]);
        }
    }

    /** 0912345678 → 218912345678 */
    public function international(string $phone): string
    {
        return '218'.ltrim($phone, '0');
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
