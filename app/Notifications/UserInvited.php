<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvited extends Notification
{
    use Queueable;

    public function __construct(
        public Invitation $invitation,
        public string $token,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $where = $this->invitation->account?->name ?? config('app.name');

        return (new MailMessage)
            ->subject("You've been invited to {$where}")
            ->line("You've been invited to join {$where} as {$this->invitation->role->label()}.")
            ->action('Accept invitation', route('invitations.accept', $this->token))
            ->line('This invitation expires in '.Invitation::EXPIRES_AFTER_DAYS.' days.')
            ->line('If you were not expecting this, you can ignore this email.');
    }
}
