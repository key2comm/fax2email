<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An email domain whose senders may fax by email for an account, without
 * being invited to the portal. Matches the From address's domain exactly,
 * so example.com does not cover sales.example.com.
 */
class SenderDomain extends Model
{
    /**
     * Free webmail and ISP domains: anyone can get an address there, so
     * allowing one would let strangers fax on an account's numbers.
     */
    public const PUBLIC_DOMAINS = [
        'aol.com', 'att.net', 'bell.net', 'comcast.net', 'cox.net', 'fastmail.com', 'gmail.com', 'gmx.com', 'gmx.net',
        'googlemail.com', 'hotmail.ca', 'hotmail.co.uk', 'hotmail.com', 'hushmail.com', 'icloud.com', 'live.ca',
        'live.com', 'mac.com', 'mail.com', 'me.com', 'msn.com', 'outlook.com', 'pm.me', 'proton.me', 'protonmail.com',
        'rogers.com', 'shaw.ca', 'sympatico.ca', 'telus.net', 'tutanota.com', 'verizon.net', 'videotron.ca',
        'yahoo.ca', 'yahoo.co.uk', 'yahoo.com', 'yandex.com', 'ymail.com', 'zoho.com',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'account_id',
        'domain',
        'created_by',
    ];

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
