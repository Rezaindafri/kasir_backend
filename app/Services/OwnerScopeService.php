<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Resolves the operational owner group for the current account. */
class OwnerScopeService
{
    public function idFor(User $user): int
    {
        if ($user->role === 'owner') {
            return (int) $user->id;
        }

        if ($user->role === 'kasir' && $user->owner_id) {
            $owner = User::query()
                ->whereKey($user->owner_id)
                ->where('role', 'owner')
                ->where('is_active', true)
                ->first();

            if ($owner) {
                return (int) $owner->id;
            }
        }

        throw new AuthorizationException('Akun tidak memiliki kelompok owner operasional yang aktif.');
    }
}
