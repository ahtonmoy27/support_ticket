<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthenticationCodeNotification extends Notification
{
    use Queueable;

    public function __construct(public string $code, public string $purpose) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $action = $this->purpose === 'registration' ? 'verify your email' : 'reset your password';

        return (new MailMessage)
            ->subject('Your Support Desk verification code')
            ->greeting('Support Desk verification')
            ->line("Use this code to {$action}:")
            ->line($this->code)
            ->line('This code expires in 15 minutes. If you did not request it, ignore this email.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['purpose' => $this->purpose];
    }
}
