<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Provisioner = 'provisioner';
    case AccountOwner = 'account_owner';
    case AccountUser = 'account_user';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Provisioner => 'Provisioner',
            self::AccountOwner => 'Account Owner',
            self::AccountUser => 'Account User',
        };
    }

    /**
     * Staff roles operate across all accounts and never belong to one.
     */
    public function isStaff(): bool
    {
        return $this === self::Admin || $this === self::Provisioner;
    }

    /**
     * The roles a user with this role may invite someone as, or promote someone to.
     *
     * @return list<self>
     */
    public function assignableRoles(): array
    {
        return match ($this) {
            self::Admin => self::cases(),
            self::Provisioner, self::AccountOwner => [self::AccountOwner, self::AccountUser],
            self::AccountUser => [],
        };
    }

    /**
     * The roles a user with this role may edit or delete. Account owners can
     * promote users to owner, but cannot remove or demote other owners.
     *
     * @return list<self>
     */
    public function manageableRoles(): array
    {
        return match ($this) {
            self::Admin => self::cases(),
            self::Provisioner => [self::AccountOwner, self::AccountUser],
            self::AccountOwner => [self::AccountUser],
            self::AccountUser => [],
        };
    }

    public function canAssign(self $role): bool
    {
        return in_array($role, $this->assignableRoles(), true);
    }

    public function canManage(self $role): bool
    {
        return in_array($role, $this->manageableRoles(), true);
    }
}
