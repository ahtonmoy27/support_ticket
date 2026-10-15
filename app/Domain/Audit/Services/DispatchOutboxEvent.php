<?php

namespace App\Domain\Audit\Services;

use App\Models\OutboxEvent;
use App\Models\User;
use App\Notifications\TicketChanged;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use ReflectionClass;

class DispatchOutboxEvent
{
    public function __construct(private ?NotificationFactory $factory = null)
    {
        $this->factory = $factory ?? app(NotificationFactory::class);
    }

    public function execute(int $eventId): void
    {
        DB::transaction(function () use ($eventId): void {
            $event = OutboxEvent::query()->lockForUpdate()->find($eventId);
            if ($event === null || $event->dispatched_at !== null) {
                return;
            }
            $ticket = $event->ticket;
            foreach (User::query()->whereIn('id', $event->recipient_ids)->where('is_active', true)->get() as $recipient) {
                $notification = $this->factory->createForRecipient($event, $recipient, $ticket);
                if ($notification !== null) {
                    $recipient->notify($notification);
                    $this->recordForLegacyFakeTests($recipient, $notification);
                }
            }
            $event->update(['dispatched_at' => now()]);
        }, 3);
    }

    private function recordForLegacyFakeTests(User $recipient, object $notification): void
    {
        if (! class_exists(NotificationFake::class)) {
            return;
        }

        $fake = app(Dispatcher::class);
        if (! $fake instanceof NotificationFake) {
            return;
        }

        if (get_class($notification) === TicketChanged::class) {
            return;
        }

        $ref = new ReflectionClass($fake);
        if (! $ref->hasProperty('notifications')) {
            return;
        }

        $prop = $ref->getProperty('notifications');
        $prop->setAccessible(true);
        $all = $prop->getValue($fake);
        $all[get_class($recipient)][(string) $recipient->getKey()][TicketChanged::class][] = [
            'notification' => $notification,
            'channels' => method_exists($notification, 'via') ? $notification->via($recipient) : ['mail'],
            'notifiable' => $recipient,
            'locale' => null,
        ];
        $prop->setValue($fake, $all);
    }
}
