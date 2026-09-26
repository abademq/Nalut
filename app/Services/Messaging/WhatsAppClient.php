<?php

namespace App\Services\Messaging;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Cloud API (Meta) — إرسال قوالب معتمدة.
 * خارج نافذة الـ 24 ساعة ما ينفعش غير القوالب، فكل الإرسال هنا بالقوالب.
 */
class WhatsAppClient
{
    public function __construct(private readonly array $cfg) {}

    public static function fromConfig(): self
    {
        return new self(config('messaging.whatsapp'));
    }

    public function isConfigured(): bool
    {
        return filled($this->cfg['token'] ?? null) && filled($this->cfg['phone_number_id'] ?? null);
    }

    /**
     * @param  string  $to  رقم دولي بدون + (2189XXXXXXXX)
     * @param  array<int, string>  $bodyParams  قيم {{1}} {{2}} ... بالترتيب
     * @param  array<int, string>|null  $urlButtonParams  لزر الرابط/نسخ الرمز (قوالب التحقق)
     * @return string معرّف الرسالة عند Meta
     */
    public function sendTemplate(string $to, string $template, string $language, array $bodyParams = [], ?array $urlButtonParams = null): string
    {
        if (! $this->isConfigured()) {
            throw MessagingException::notConfigured('واتساب');
        }

        $components = [];

        if ($bodyParams) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => self::clean($v)], array_values($bodyParams)),
            ];
        }

        if ($urlButtonParams) {
            $components[] = [
                'type'       => 'button',
                'sub_type'   => 'url',
                'index'      => '0',
                'parameters' => array_map(fn ($v) => ['type' => 'text', 'text' => (string) $v], $urlButtonParams),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => array_filter([
                'name'       => $template,
                'language'   => ['code' => $language],
                'components' => $components ?: null,
            ]),
        ];

        $url = rtrim($this->cfg['base_url'], '/').'/'.$this->cfg['api_version'].'/'.$this->cfg['phone_number_id'].'/messages';

        try {
            $res = Http::withToken($this->cfg['token'])
                ->acceptJson()->asJson()
                ->timeout((int) ($this->cfg['timeout'] ?? 15))
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new MessagingException('تعذّر الاتصال بواتساب: '.$e->getMessage(), 0, $e);
        }

        if (! $res->successful()) {
            $err = $res->json('error.message') ?? $res->body();
            Log::error('WhatsApp API error', ['status' => $res->status(), 'body' => $res->json() ?? $res->body()]);

            throw new MessagingException("واتساب رفض الرسالة ({$res->status()}): {$err}", $res->status());
        }

        return (string) ($res->json('messages.0.id') ?? '');
    }

    /** واتساب ما يقبلش سطور جديدة ولا مسافات كثيرة داخل متغيرات القالب */
    public static function clean(mixed $v): string
    {
        $v = preg_replace('/[\r\n\t]+/u', ' · ', (string) $v);
        $v = preg_replace('/ {4,}/', '   ', $v);

        return trim($v) === '' ? '-' : mb_substr($v, 0, 1000);
    }
}
