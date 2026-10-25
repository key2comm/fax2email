<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A received or sent fax. Files live on the local disk under faxes/.
 *
 * Received ("inbound"): "received" (call finished) -> "delivered" (PDF made
 * and emailed), or "failed" if the call produced no usable fax.
 *
 * Sent ("outbound"): "queued" -> "sending" (call in progress) -> "sent", or
 * back to "queued" with next_attempt_at set for a retry, or "failed".
 */
class Fax extends Model
{
    use Prunable;

    public const KEEP_DAYS = 90;

    public const STATUSES = ['received', 'delivered', 'queued', 'sending', 'sent', 'failed'];

    /**
     * Attempts per outgoing fax before giving up, and the wait between them.
     */
    public const MAX_ATTEMPTS = 3;

    public const RETRY_AFTER_MINUTES = 5;

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
        'source',
        'user_id',
        'sender_email',
        'status',
        'called_number',
        'destination_number',
        'destination_name',
        'caller_number',
        'caller_name',
        'remote_station_id',
        'pages',
        'result_text',
        'hangup_cause',
        'attempts',
        'next_attempt_at',
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
            'next_attempt_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'outbound';
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
