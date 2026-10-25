<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Fax;
use App\Models\User;

class FaxPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->account_id !== null;
    }

    public function view(User $user, Fax $fax): bool
    {
        return $user->isStaff() || ($fax->account_id !== null && $user->account_id === $fax->account_id);
    }

    /**
     * Sending a fax email again is for staff and the account's owners.
     */
    public function resend(User $user, Fax $fax): bool
    {
        return $user->isStaff() || ($user->role === Role::AccountOwner && $user->account_id === $fax->account_id);
    }
}
