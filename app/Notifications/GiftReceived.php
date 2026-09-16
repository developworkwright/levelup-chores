<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to the kid a sibling picked for today's gift.
 *
 * The push is half of the gift: a ticket that turned up silently in a balance
 * nobody was looking at is a number going up, and "Ava gave you a ticket" is
 * somebody thinking of you. It names the giver for that reason.
 */
class GiftReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $body,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->icon('/icons/icon-192.png')
            ->body($this->body)
            ->tag('sibling-gift')
            ->renotify()
            ->data(['url' => '/kid/home?row=gift']);
    }
}
