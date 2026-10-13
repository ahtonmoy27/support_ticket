<?php

namespace App\Notifications\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

class DatabaseChannel
{
    public function send(object $notifiable, Notification $notification): Model
    {
        $hash = md5('support-outbox:'.$notification->eventId.':'.$notifiable->getKey());
        $id = substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);

        return $notifiable->notifications()->firstOrCreate(['id' => $id], ['type' => $notification::class, 'data' => $notification->toArray($notifiable), 'read_at' => null]);
    }
}
