<?php

namespace App\Policies;

use App\Models\User;

class TruckPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canViewRegisters();
    }

    public function view(User $user, $model = null): bool
    {
        return $user->role->canViewRegisters();
    }

    public function create(User $user): bool
    {
        return $user->role->canManageMasterData();
    }

    public function update(User $user, $model = null): bool
    {
        return $user->role->canManageMasterData();
    }

    /** Registers are deactivated, never deleted: history refers to them. */
    public function delete(User $user, $model = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
