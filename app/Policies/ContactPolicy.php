<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

/**
 * The address book is shared by everyone in an account: owners and users can
 * all add, change and remove contacts. Staff don't belong to an account.
 */
class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->account_id !== null;
    }

    public function create(User $user): bool
    {
        return $user->account_id !== null;
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->account_id !== null && $user->account_id === $contact->account_id;
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $this->update($user, $contact);
    }
}
