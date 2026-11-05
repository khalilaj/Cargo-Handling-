<?php

namespace App\Policies;

use App\Models\User;

class DeliveryOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, $model = null): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role->canManageMasterData();
    }

    public function update(User $user, $model = null): bool
    {
        return $user->role->canManageMasterData();
    }

    /** Operational data is never deleted; corrections are made with reversal movements. */
    public function delete(User $user, $model = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
