<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case OPERATOR = 'OPERATOR';

    public function isSuperAdmin(): bool
    {
        return $this === self::SUPER_ADMIN;
    }
}
