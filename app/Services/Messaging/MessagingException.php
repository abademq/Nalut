<?php

namespace App\Services\Messaging;

use RuntimeException;

class MessagingException extends RuntimeException
{
    /** true = القناة مش مضبوطة (ما فيش توكن) — مش خطأ إرسال */
    public bool $notConfigured = false;

    public static function notConfigured(string $channel): self
    {
        $e = new self("قناة {$channel} مش مضبوطة في .env");
        $e->notConfigured = true;

        return $e;
    }
}
