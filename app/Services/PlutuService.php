<?php

namespace App\Services;

use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * تكامل بوابة بلوتو — https://docs.plutu.ly
 *
 * المفاتيح تجي من قاعدة البيانات (لوحة التحكم) مش من .env،
 * باش تقدر تغيّرها أو تبدّل بين التجريبي والمباشر بدون نشر كود.
 */
class PlutuService
{
    private const BASE = 'https://api.plutus.ly/api/v1/transaction';

    public function __construct(private readonly PaymentGateway $gateway) {}

    public static function for(string $key): self
    {
        $gateway = PaymentGateway::where('key', $key)->firstOrFail();

        if (! $gateway->isConfigured()) {
            throw ValidationException::withMessages([
                'gateway' => 'بوابة الدفع مش مضبوطة. تواصل مع الإدارة.',
            ]);
        }

        return new self($gateway);
    }

    private function headers(): array
    {
        return [
            'X-API-KEY'     => $this->gateway->apiKey(),
            'Authorization' => 'Bearer '.$this->gateway->accessToken(),
            'Accept'        => 'application/json',
        ];
    }

    /** ===== سداد / إدفعلي: إرسال رمز التحقق ===== */
    public function sendOtp(string $mobile, string $birthYear, float $amount): array
    {
        $endpoint = $this->gateway->key === 'adfali' ? 'adfalipayment' : 'sadadapi';

        $res = Http::withHeaders($this->headers())
            ->asMultipart()
            ->timeout(30)
            ->post(self::BASE."/$endpoint/verify", [
                ['name' => 'mobile_number', 'contents' => $mobile],
                ['name' => 'birth_year',    'contents' => $birthYear],
                ['name' => 'amount',        'contents' => number_format($amount, 2, '.', '')],
            ]);

        return $this->handle($res, 'ما نجحش إرسال رمز التحقق.');
    }

    /** ===== سداد / إدفعلي: تأكيد الدفع ===== */
    public function confirmOtp(
        string $processId,
        string $code,
        float $amount,
        string $invoiceNo,
        ?string $customerIp = null
    ): array {
        $endpoint = $this->gateway->key === 'adfali' ? 'adfalipayment' : 'sadadapi';

        $form = [
            ['name' => 'process_id', 'contents' => $processId],
            ['name' => 'code',       'contents' => $code],
            ['name' => 'amount',     'contents' => number_format($amount, 2, '.', '')],
            ['name' => 'invoice_no', 'contents' => $invoiceNo],
        ];

        if ($customerIp) {
            $form[] = ['name' => 'customer_ip', 'contents' => $customerIp];
        }

        $res = Http::withHeaders($this->headers())
            ->asMultipart()
            ->timeout(45)
            ->post(self::BASE."/$endpoint/confirm", $form);

        return $this->handle($res, 'ما نجحش تأكيد الدفع.');
    }

    /** ===== البطاقات والمحافظ: صفحة دفع خارجية ===== */
    public function checkout(
        float $amount,
        string $invoiceNo,
        string $returnUrl,
        ?string $customerIp = null
    ): array {
        $endpoint = match ($this->gateway->key) {
            'mpgs'  => 'mpgs',
            'tlync' => 'tlync',
            default => 'localbankcards',
        };

        $form = [
            ['name' => 'amount',     'contents' => number_format($amount, 2, '.', '')],
            ['name' => 'invoice_no', 'contents' => $invoiceNo],
            ['name' => 'return_url', 'contents' => $returnUrl],
            ['name' => 'lang',       'contents' => 'ar'],
        ];

        if ($customerIp) {
            $form[] = ['name' => 'customer_ip', 'contents' => $customerIp];
        }

        $res = Http::withHeaders($this->headers())
            ->asMultipart()
            ->timeout(30)
            ->post(self::BASE."/$endpoint/confirm", $form);

        return $this->handle($res, 'ما نجحش فتح صفحة الدفع.');
    }

    /**
     * التحقق من صحة رد بلوتو عبر HMAC-SHA256.
     * بدونه أي واحد يقدر يزوّر رابط نجاح ويشحن محفظته مجاناً.
     */
    public static function verifyCallback(array $params, string $secretKey): bool
    {
        $received = $params['hashed'] ?? null;

        if (! $received) {
            return false;
        }

        unset($params['hashed']);

        $payload = urldecode(http_build_query($params));
        $expected = strtoupper(hash_hmac('sha256', $payload, $secretKey));

        return hash_equals($expected, strtoupper($received));
    }

    /** قراءة رد بلوتو وتحويل أخطائها لرسائل عربية */
    private function handle($res, string $fallback): array
    {
        $body = $res->json() ?? [];

        if ($res->successful() && ($body['status'] ?? 0) == 200) {
            return $body['result'] ?? [];
        }

        $code    = $body['error']['code'] ?? 'UNKNOWN';
        $message = $body['error']['message'] ?? $fallback;

        Log::warning('Plutu error', [
            'gateway' => $this->gateway->key,
            'code'    => $code,
            'body'    => $body,
        ]);

        throw ValidationException::withMessages([
            'payment' => self::arabicError($code, $message),
        ]);
    }

    private static function arabicError(string $code, string $fallback): string
    {
        return match ($code) {
            'INVALID_MOBILE_NUMBER'   => 'رقم الهاتف غير صحيح — لازم يبدا بـ091 أو 093.',
            'INVALID_BIRTH_YEAR'      => 'سنة الميلاد غير صحيحة.',
            'INVALID_OTP', 'INVALID_CODE' => 'رمز التحقق غير صحيح.',
            'INSUFFICIENT_BALANCE'    => 'الرصيد ما يكفيش في حسابك.',
            'INVALID_AMOUNT'          => 'المبلغ غير صحيح.',
            'DUPLICATE_INVOICE_NO'    => 'رقم الفاتورة مكرر — جرّب من جديد.',
            'TRANSACTION_FAILED'      => 'فشلت العملية. جرّب مرة ثانية.',
            'UNAUTHENTICATED', 'INVALID_ACCESS_TOKEN' => 'إعدادات بوابة الدفع غير صحيحة.',
            default                   => $fallback,
        };
    }
}
