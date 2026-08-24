<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Http\Middleware\Authenticate as BaseAuthenticate;

class FilamentAuthenticate extends BaseAuthenticate
{
    protected function redirectTo($request): ?string
    {
        try {
            if (User::where('role', UserRole::SUPER_ADMIN)->doesntExist()) {
                return route('setup');
            }
        } catch (\Throwable) {
        }

        return parent::redirectTo($request);
    }
}
