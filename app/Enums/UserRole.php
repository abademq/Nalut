<?php

namespace App\Enums;

enum UserRole: string
{
    case Customer = 'customer';
    case Store    = 'store';
    case Driver   = 'driver';
    case Admin    = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'زبون',
            self::Store    => 'متجر',
            self::Driver   => 'سائق',
            self::Admin    => 'إدارة',
        };
    }
}
