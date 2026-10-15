<?php

namespace App\Notifications;

use App\Domain\Auth\Services\AuthenticationCodeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AuthenticationCodeNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $code, public string $purpose)
    {
        $this->onQueue('notifications');
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [15, 30, 60];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable->is_active && app(AuthenticationCodeService::class)->isCurrent($notifiable->id, $this->purpose, $this->code);
    }

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
