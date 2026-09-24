<?php

namespace App\Services\Otp;

use RuntimeException;
use Throwable;

class ResalaException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        public readonly bool $insufficientCredit = false,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
