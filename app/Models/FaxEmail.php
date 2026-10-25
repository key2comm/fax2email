<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email received for email-to-fax, and what was done with it.
 */
class FaxEmail extends Model
{
    use MassPrunable;

    public const KEEP_DAYS = 90;

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'from_address',
        'recipient',
        'subject',
        'client_ip',
        'spf',
        'dkim_domain',
        'status',
        'reason',
        'fax_id',
    ];

    /**
     * @return BelongsTo<Fax, $this>
     */
    public function fax(): BelongsTo
    {
        return $this->belongsTo(Fax::class);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
