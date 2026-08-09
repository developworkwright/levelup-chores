<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent when a bounty changes hands: posted, taken, reported done, or hired by
 * a parent. Only lands if the profile has a push subscription; the count on the
 * Trades & Jobs tab is the reliable signal, exactly as with sibling trades.
 */
class BountyUpdate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly string $url = '/kid/trades',
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
            ->tag('bounty')
            ->renotify()
            ->data(['url' => $this->url]);
    }
}
