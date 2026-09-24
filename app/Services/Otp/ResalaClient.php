<?php

namespace App\Services\Otp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * عميل منصة رسالة (resala.ly) — SMS للأرقام الليبية.
 *
 * ملاحظات مهمة من توثيق المنصة:
 *  - المنصة هي اللي تولّد الرمز وترجعه في "pin"، وما عندهاش endpoint للتحقق،
 *    فالتحقق يصير عندنا (OtpService).
 *  - ممنوع إعادة المحاولة التلقائية لطلبات الإرسال (POST) — تبعت رسالتين وتخصم مرتين.
 *    إعادة المحاولة مسموحة لطلبات القراءة (GET) فقط.
 */
class ResalaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly bool $test = false,
        private readonly int $timeout = 15,
    ) {}

    public static function fromConfig(): self
    {
        $c = config('otp.resala');

        return new self(
            rtrim((string) $c['base_url'], '/'),
            $c['token'] ?? null,
            (bool) ($c['test'] ?? false),
            (int) ($c['timeout'] ?? 15),
        );
    }

    /**
     * يبعت رمز تحقق. يرجّع: ['pin' => '1234', 'id' => '...', 'content' => '...']
     *
     * @param  string  $phone  بصيغة 2189XXXXXXXX
     */
    public function sendPin(string $phone, int $length = 6, ?string $serviceName = null, ?string $autofillHash = null): array
    {
        $query = array_filter([
            'len'          => in_array($length, [4, 5, 6], true) ? $length : 6,
            'service_name' => $serviceName,
            'autofill'     => $autofillHash ? substr($autofillHash, 0, 32) : null,
        ], fn ($v) => $v !== null && $v !== '');

        // ?test بدون قيمة — نلصقه يدوياً
        $url = '/pins?'.($this->test ? 'test&' : '').http_build_query($query);

        $response = $this->send(fn () => $this->http()->post($url, ['phone' => $phone]));

        $pin = (string) ($response->json('pin') ?? '');

        if ($pin === '') {
            throw new ResalaException('رسالة ما رجّعتش الرمز في الاستجابة.', $response->status());
        }

        return [
            'pin'     => $pin,
            'id'      => $response->json('id'),
            'content' => $response->json('content'),
        ];
    }

    /**
     * رسالة من قالب معتمد في لوحة رسالة.
     *
     * @param  array<int, array<string, string>>  $records  [['phone' => '2189..', '$1' => '...'], ...]
     */
    public function sendTemplate(string $templateId, array $records): array
    {
        $url = '/messages/send-template?'.http_build_query(['sms_template_id' => $templateId]);

        return $this->send(fn () => $this->http()->post($url, ['records' => $records]))->json() ?? [];
    }

    /**
     * سجل الإرسال وحالاته: accepted · sent · delivered · undelivered
     */
    public function sentLog(string $source = 'pin|message', int $page = 1, int $perPage = 10): array
    {
        $query = http_build_query([
            'filters'  => 'source:'.$source,
            'page'     => $page,
            'paginate' => $perPage,
            'sorts'    => '-created_at',
        ]);

        // القراءة فقط يُسمح فيها بإعادة المحاولة
        return $this->send(
            fn () => $this->http()->retry(2, 300, fn ($e) => $e instanceof ConnectionException)->get('/sent-view?'.$query)
        )->json() ?? [];
    }

    private function http(): PendingRequest
    {
        if (blank($this->token)) {
            throw new ResalaException('RESALA_API_TOKEN غير مضبوط في .env', 401);
        }

        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }

    private function send(callable $call): Response
    {
        try {
            /** @var Response $response */
            $response = $call();
        } catch (ConnectionException $e) {
            throw new ResalaException('تعذّر الاتصال بمنصة رسالة: '.$e->getMessage(), 0, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $body = $response->json() ?? $response->body();
        $message = is_array($body) ? (string) ($body['message'] ?? json_encode($body, JSON_UNESCAPED_UNICODE)) : (string) $body;

        $error = match (true) {
            $status === 401 => 'توكن رسالة غير صحيح — راجع RESALA_API_TOKEN.',
            $status === 403 => 'حساب رسالة ما عندوش صلاحية لهذه العملية.',
            $status === 422 => 'رسالة رفضت البيانات: '.$message,
            $status === 400 && preg_match('/credit|balance|رصيد/iu', $message) === 1 => 'رصيد رسالة غير كافٍ.',
            default => "خطأ من رسالة ({$status}): {$message}",
        };

        Log::error('Resala API error', ['status' => $status, 'body' => $body]);

        throw new ResalaException($error, $status, null, $status === 400 && str_contains($error, 'رصيد'));
    }
}
