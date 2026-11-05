<?php

namespace App\Policies;

use App\Models\User;

/** Read-only for everyone who may see it; nobody writes through the UI. */
class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role->canViewAudit();
    }

    public function view(User $user, $model = null): bool
    {
        return $user->role->canViewAudit();
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
