<?php

namespace App\Policies;

use App\Models\Penduduk;
use App\Models\User;

/**
 * SIPETA is a single-admin system (ADR-005): every ability is granted to the
 * authenticated operator. The policy exists for future-proofing, per
 * `.ai/filament.md` §10.
 */
class PendudukPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Penduduk $penduduk): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Penduduk $penduduk): bool
    {
        return true;
    }

    public function delete(User $user, Penduduk $penduduk): bool
    {
        return true;
    }

    public function deleteAny(User $user): bool
    {
        return true;
    }
}
