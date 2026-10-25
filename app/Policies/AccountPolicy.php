<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Account $account): bool
    {
        return $user->canManageAccountUsers($account);
    }

    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    public function update(User $user, Account $account): bool
    {
        return $user->isStaff();
    }

    /**
     * Deleting an account removes all of its users and faxes, so only admins may do it.
     */
    public function delete(User $user, Account $account): bool
    {
        return $user->role === Role::Admin;
    }
}
