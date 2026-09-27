<?php

namespace App\Console\Commands;

use App\Services\Messaging\MessagingException;
use App\Services\Messaging\WhatsAppClient;
use App\Support\Options;
use Illuminate\Console\Command;

/**
 * فحص إعداد واتساب: يبعت قالب رمز التحقق لرقم ويوري رد Meta كما هو.
 *   php artisan whatsapp:test 0912345678
 */
class WhatsAppTest extends Command
{
    protected $signature = 'whatsapp:test {phone : رقم ليبي 09XXXXXXXX أو دولي 2189XXXXXXXX}';

    protected $description = 'Send the OTP WhatsApp template to a number and show Meta\'s answer';

    public function handle(): int
    {
        $cfg = config('messaging.whatsapp');
        $to = preg_replace('/\D/', '', (string) $this->argument('phone'));
        $to = str_starts_with($to, '0') ? '218'.substr($to, 1) : $to;

        $this->line('القناة في إعدادات التشغيل: '.Options::get('otp.channel'));
        $this->line('Phone number ID: '.($cfg['phone_number_id'] ?: '— فاضي'));
        $this->line('Token: '.(filled($cfg['token']) ? 'موجود ('.strlen($cfg['token']).' حرف)' : '— فاضي'));
        $this->line("القالب: {$cfg['otp_template']} · اللغة: {$cfg['otp_language']} · إلى: $to");

        if (! WhatsAppClient::fromConfig()->isConfigured()) {
            $this->error('واتساب مش مضبوط: WHATSAPP_TOKEN أو WHATSAPP_PHONE_NUMBER_ID فاضي — بعد التعديل: php artisan config:cache');

            return self::FAILURE;
        }

        try {
            $id = WhatsAppClient::fromConfig()->sendTemplate($to, $cfg['otp_template'], $cfg['otp_language'], ['1234'], ['1234']);
            $this->info("✔ انبعتت — رقم الرسالة عند Meta: $id (الرمز التجريبي 1234)");

            return self::SUCCESS;
        } catch (MessagingException $e) {
            $this->error('✘ '.$e->getMessage());
            $msg = $e->getMessage();
            $hint = match (true) {
                str_contains($msg, '132001') || str_contains($msg, 'does not exist') => 'القالب مش موجود بالاسم/اللغة هذي، أو لسه ما تمتش الموافقة عليه.',
                str_contains($msg, '131030') || str_contains($msg, 'allowed list') => 'رقم الاختبار يبعت بس للأرقام المضافة في API Setup ← To.',
                str_contains($msg, '190') || str_contains($msg, 'expired') || str_contains($msg, 'OAuth') => 'الـToken منتهي أو غلط — المؤقت يعيش 24 ساعة. خوذ Token دائم من System User.',
                str_contains($msg, '100') => 'معرّف رقم الهاتف غلط، أو شكل القالب ما يطابقش (المتغيرات/الزر).',
                default => null,
            };
            if ($hint) {
                $this->warn('السبب المحتمل: '.$hint);
            }

            return self::FAILURE;
        }
    }
}
