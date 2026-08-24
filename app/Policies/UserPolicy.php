<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        if (! $user->isSuperAdmin()) {
            return false;
        }

        if ($user->getKey() === $model->getKey()) {
            return false;
        }

        if ($model->role?->isSuperAdmin()) {
            return false;
        }

        return true;
    }

    public function changePassword(User $user, User $model): bool
    {
        return $user->isSuperAdmin() && $user->getKey() !== $model->getKey();
    }

    public function restore(User $user, User $model): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
