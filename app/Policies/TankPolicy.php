<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ArchivesRegisters;

class TankPolicy
{
    use ArchivesRegisters;

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
}
