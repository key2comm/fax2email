<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'timezone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isStaff(): bool
    {
        return $this->role->isStaff();
    }

    /**
     * Whether this user may manage the users of the given account.
     */
    public function canManageAccountUsers(?Account $account): bool
    {
        if ($this->isStaff()) {
            return true;
        }

        return $account !== null
            && $this->role === Role::AccountOwner
            && $this->account_id === $account->id;
    }

    /**
     * Limit a user query to the users the viewer is allowed to see.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, self $viewer): void
    {
        if (! $viewer->isStaff()) {
            $query->where('account_id', $viewer->account_id);
        }
    }
}
