<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A received fax. Status moves from "received" (FreeSWITCH finished the call)
 * to "delivered" (PDF made and emailed), or "failed" if the call did not
 * produce a usable fax. Files live on the local disk under faxes/.
 */
class Fax extends Model
{
    use Prunable;

    public const KEEP_DAYS = 90;

    public const STATUSES = ['received', 'delivered', 'failed'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'call_uuid',
        'account_id',
        'fax_number_id',
        'direction',
        'status',
        'called_number',
        'caller_number',
        'caller_name',
        'remote_station_id',
        'pages',
        'result_text',
        'tiff_path',
        'pdf_path',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emailed_at' => 'datetime',
            'pages' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<FaxNumber, $this>
     */
    public function faxNumber(): BelongsTo
    {
        return $this->belongsTo(FaxNumber::class);
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
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    /**
     * Delete the fax's files along with the record.
     */
    protected function pruning(): void
    {
        Storage::disk('local')->delete(array_filter([$this->tiff_path, $this->pdf_path]));
    }
}
