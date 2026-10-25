<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A phone number (DID) that receives faxes for an account. Numbers are stored
 * as digits only; see normalize() for how provider formats are matched.
 */
class FaxNumber extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'number',
        'label',
        'recipients',
        'enabled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipients' => 'array',
            'enabled' => 'boolean',
        ];
    }

    /**
     * Digits only, so "+1 (555) 123-4567", "15551234567" and "sip:+15551234567@host" compare equal.
     */
    public static function normalize(string $number): string
    {
        if (preg_match('/^sips?:([^@;]+)/i', $number, $m)) {
            $number = $m[1];
        }

        return preg_replace('/\D/', '', $number);
    }

    /**
     * Find the enabled number a provider dialled. Providers in North America
     * send either 10 or 11 digits, so a leading 1 is tried both ways.
     */
    public static function findForCall(string $dialled): ?self
    {
        $digits = self::normalize($dialled);
        if (strlen($digits) < 3) {
            return null;
        }

        $candidates = [$digits];
        if (strlen($digits) === 10) {
            $candidates[] = '1'.$digits;
        } elseif (strlen($digits) === 11 && $digits[0] === '1') {
            $candidates[] = substr($digits, 1);
        }

        return self::where('enabled', true)->whereIn('number', $candidates)->first();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<Fax, $this>
     */
    public function faxes(): HasMany
    {
        return $this->hasMany(Fax::class);
    }

    /**
     * Where received faxes are emailed: the configured recipients, or the account's owners if none are set.
     *
     * @return list<string>
     */
    public function deliveryAddresses(): array
    {
        if ($this->recipients) {
            return array_values($this->recipients);
        }

        return $this->account->users()->where('role', Role::AccountOwner)->pluck('email')->all();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        if (! $viewer->isStaff()) {
            $query->where('account_id', $viewer->account_id);
        }
    }

    /**
     * A readable version of the number, e.g. +1 (555) 123-4567 for North American numbers.
     */
    public function formatted(): string
    {
        $n = $this->number;
        if (strlen($n) === 11 && $n[0] === '1') {
            return sprintf('+1 (%s) %s-%s', substr($n, 1, 3), substr($n, 4, 3), substr($n, 7));
        }

        return strlen($n) > 7 ? "+{$n}" : $n;
    }
}
