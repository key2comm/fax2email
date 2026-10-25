<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\FaxNumber;
use App\Models\User;

class FaxNumberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->role === Role::AccountOwner;
    }

    /**
     * Adding numbers and moving them between accounts is for staff.
     */
    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Staff can change everything; Account Owners can change the label and
     * recipients of their own account's numbers.
     */
    public function update(User $user, FaxNumber $number): bool
    {
        return $user->isStaff()
            || ($user->role === Role::AccountOwner && $user->account_id === $number->account_id);
    }

    public function delete(User $user, FaxNumber $number): bool
    {
        return $user->isStaff();
    }
}
