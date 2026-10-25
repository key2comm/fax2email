<?php

namespace App\Policies;

use App\Models\Invitation;
use App\Models\User;

class InvitationPolicy
{
    /**
     * Anyone who could have sent the invitation may revoke it.
     */
    public function delete(User $user, Invitation $invitation): bool
    {
        return $user->can('invite', [User::class, $invitation->role, $invitation->account]);
    }
}
