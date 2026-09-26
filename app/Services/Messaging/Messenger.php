<?php

namespace App\Services\Messaging;

use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Services\Otp\ResalaClient;
use App\Services\Otp\ResalaException;

/**
 * نقطة الإرسال الوحيدة للرسائل بالقوالب (واتساب أو SMS).
 * كل رسالة تتسجّل في message_logs — ناجحة، فاشلة، أو متخطّاة لأن القناة مش مضبوطة.
 */
class Messenger
{
    /** 0912345678 / 218912345678 / +218 91... → 218912345678 */
    public static function international(string $phone): ?string
    {
        $d = preg_replace('/\D/', '', $phone);

        if (preg_match('/^09\d{8}$/', $d)) {
            return '218'.substr($d, 1);
        }
        if (preg_match('/^9\d{8}$/', $d)) {
            return '218'.$d;
        }
        if (preg_match('/^\d{10,15}$/', $d)) {
            return $d;
        }

        return null;
    }

    /**
     * @return MessageLog السجل (status: sent | failed | skipped)
     */
    public function send(MessageTemplate $template, string $phone, array $vars, string $context, ?int $contextId = null): MessageLog
    {
        $to = self::international($phone);
        $log = ['channel' => $template->channel, 'phone' => $to ?? $phone, 'message_template_id' => $template->id,
            'context' => $context, 'context_id' => $contextId, 'created_at' => now()];

        if (! $to) {
            return MessageLog::create($log + ['status' => 'failed', 'error' => 'رقم غير صحيح']);
        }

        $params = $template->resolveParams($vars);

        try {
            $id = $template->channel === 'whatsapp'
                ? WhatsAppClient::fromConfig()->sendTemplate($to, $template->provider_ref, $template->language ?: 'ar', $params)
                : $this->viaResala($template->provider_ref, $to, $params);

            return MessageLog::create($log + ['status' => 'sent', 'provider_id' => $id]);
        } catch (MessagingException $e) {
            return MessageLog::create($log + ['status' => $e->notConfigured ? 'skipped' : 'failed', 'error' => $e->getMessage()]);
        } catch (ResalaException $e) {
            return MessageLog::create($log + ['status' => $e->getCode() === 401 ? 'skipped' : 'failed', 'error' => $e->getMessage()]);
        }
    }

    private function viaResala(string $templateId, string $to, array $params): string
    {
        if (blank(config('otp.resala.token'))) {
            throw MessagingException::notConfigured('رسالة (SMS)');
        }

        $record = ['phone' => $to];
        foreach (array_values($params) as $i => $v) {
            $record['$'.($i + 1)] = $v;
        }

        $res = ResalaClient::fromConfig()->sendTemplate($templateId, [$record]);

        return (string) ($res['id'] ?? $res['data']['id'] ?? '');
    }
}
