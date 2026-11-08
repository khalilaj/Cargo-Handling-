<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ArchivesRegisters;

class BerthPolicy
{
    use ArchivesRegisters;

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
}
