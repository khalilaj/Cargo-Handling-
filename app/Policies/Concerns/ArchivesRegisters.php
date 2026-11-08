<?php

namespace App\Policies\Concerns;

use App\Domain\Access\Role;
use App\Models\User;

/**
 * Register records are archived, then (only then, only by an admin) deleted.
 * Whether anything still uses the record is checked by RegisterUsage when the delete is attempted.
 */
trait ArchivesRegisters
{
    public function archive(User $user, $model = null): bool
    {
        return $user->role === Role::Admin && (! is_object($model) || (bool) $model->active);
    }

    public function restore(User $user, $model = null): bool
    {
        return $user->role === Role::Admin && (! is_object($model) || ! $model->active);
    }

    /** Never offered on an active record or an unsaved one, never to anyone but an admin. */
    public function delete(User $user, $model = null): bool
    {
        return $user->role === Role::Admin && is_object($model) && $model->exists && ! $model->active;
    }

    /** No bulk delete. */
    public function deleteAny(User $user): bool
    {
        return false;
    }
}
