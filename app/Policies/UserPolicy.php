<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Account;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Whether the user can open the user management screens at all.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->assignableRoles() !== [];
    }

    /**
     * Whether the user can invite someone with the given role into the given
     * account. Staff roles never belong to an account; every other role must.
     */
    public function invite(User $user, Role $role, ?Account $account): Response
    {
        if (! $user->role->canAssign($role)) {
            return Response::deny("You cannot invite {$role->label()} users.");
        }

        if ($role->isStaff()) {
            return $account === null
                ? Response::allow()
                : Response::deny('Admins and provisioners cannot belong to an account.');
        }

        if ($account === null) {
            return Response::deny('Account users must belong to an account.');
        }

        return $user->canManageAccountUsers($account)
            ? Response::allow()
            : Response::deny('You cannot invite users to this account.');
    }

    /**
     * Whether the user can change the target's role to the given role.
     */
    public function changeRole(User $user, User $target, Role $role): Response
    {
        $response = $this->manage($user, $target);

        if ($response->denied()) {
            return $response;
        }

        if (! $user->role->canAssign($role)) {
            return Response::deny("You cannot assign the {$role->label()} role.");
        }

        if ($role->isStaff() !== $target->role->isStaff()) {
            return Response::deny('Staff and account roles cannot be swapped. Invite the person again instead.');
        }

        if ($target->role === Role::AccountOwner && $role !== Role::AccountOwner && $this->isLastOwner($target)) {
            return Response::deny('An account must keep at least one owner.');
        }

        return Response::allow();
    }

    public function delete(User $user, User $target): Response
    {
        $response = $this->manage($user, $target);

        if ($response->denied()) {
            return $response;
        }

        if ($target->role === Role::AccountOwner && $this->isLastOwner($target)) {
            return Response::deny('An account must keep at least one owner.');
        }

        return Response::allow();
    }

    /**
     * Rules shared by every action taken on another user.
     */
    private function manage(User $user, User $target): Response
    {
        if ($user->is($target)) {
            return Response::deny('You cannot change your own access.');
        }

        if (! $user->role->canManage($target->role)) {
            return Response::deny("You cannot manage {$target->role->label()} users.");
        }

        if (! $target->isStaff() && ! $user->canManageAccountUsers($target->account)) {
            return Response::deny('This user belongs to a different account.');
        }

        return Response::allow();
    }

    private function isLastOwner(User $target): bool
    {
        return $target->account->owners()->count() <= 1;
    }
}
