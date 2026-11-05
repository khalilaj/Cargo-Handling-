<?php

namespace App\Policies;

use App\Models\User;

/** Approvers and auditors see the queue. Creating and deciding go through domain actions that check roles themselves. */
class CorrectionRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canViewCorrections();
    }

    public function view(User $user, $model = null): bool
    {
        return $user->role->canViewCorrections();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, $model = null): bool
    {
        return false;
    }

    public function delete(User $user, $model = null): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
