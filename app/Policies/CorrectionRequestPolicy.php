<?php

namespace App\Policies;

use App\Models\User;

/** Everyone can see requests. Creating and deciding go through domain actions that check roles themselves. */
class CorrectionRequestPolicy
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
