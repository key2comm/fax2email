<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\SenderDomain;
use App\Models\User;

/**
 * Staff, and an account's owners, manage the account's sender domains.
 */
class SenderDomainPolicy
{
    public function create(User $user, Account $account): bool
    {
        return $user->canManageAccountUsers($account);
    }

    public function delete(User $user, SenderDomain $senderDomain): bool
    {
        return $user->canManageAccountUsers($senderDomain->account);
    }
}
