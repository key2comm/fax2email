<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * One row per email the system tries to send. A row is written as "sending"
 * just before delivery, then marked "sent" or "failed". A row left at
 * "sending" means the process stopped before the result was known.
 */
class MailLog extends Model
{
    use MassPrunable;

    public const STATUSES = ['sending', 'sent', 'failed'];

    public const KEEP_DAYS = 180;

    /**
     * The row for the email currently being sent. Mail is sent one message at
     * a time per process, so a single slot is enough.
     */
    private static ?int $pendingId = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'sender',
        'recipients',
        'cc',
        'bcc',
        'subject',
        'attachments',
        'status',
        'error',
        'message_id',
    ];

    public static function recordSending(Email $message): void
    {
        self::$pendingId = self::create([
            'sender' => self::addresses($message->getFrom()),
            'recipients' => self::addresses($message->getTo()) ?? '',
            'cc' => self::addresses($message->getCc()),
            'bcc' => self::addresses($message->getBcc()),
            'subject' => mb_substr((string) $message->getSubject(), 0, 255),
            'attachments' => count($message->getAttachments()),
            'status' => 'sending',
        ])->id;
    }

    public static function recordSent(SentMessage $sent): void
    {
        self::finishPending(['status' => 'sent', 'message_id' => $sent->getMessageId()]);
    }

    public static function recordFailure(Throwable $e): void
    {
        self::finishPending(['status' => 'failed', 'error' => $e->getMessage()]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function finishPending(array $attributes): void
    {
        if (self::$pendingId !== null) {
            self::whereKey(self::$pendingId)->update($attributes);
            self::$pendingId = null;
        }
    }

    /**
     * @param  list<Address>  $addresses
     */
    private static function addresses(array $addresses): ?string
    {
        return $addresses === [] ? null : implode(', ', array_map(fn (Address $a) => $a->toString(), $addresses));
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
