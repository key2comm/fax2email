<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An allowed (whitelisted) or blocked IP/CIDR. Manual rules are created in the
 * portal; "fail2ban" rules mirror the bans fail2ban currently holds.
 */
class FirewallRule extends Model
{
    use MassPrunable;

    public const ALLOW = 'allow';

    public const BLOCK = 'block';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'cidr',
        'source',
        'jail',
        'note',
        'created_by',
        'expires_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'unban_requested_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * Manual blocks that have expired. fail2ban rules are removed by the ban import instead.
     *
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('source', 'manual')->where('expires_at', '<=', now());
    }
}
