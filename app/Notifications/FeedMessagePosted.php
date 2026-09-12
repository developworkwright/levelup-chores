<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Somebody said something in a room you are in.
 *
 * The kids have no phones, so this push is what makes the family feed a
 * conversation rather than a noticeboard: without it a message sits until the
 * other kid happens to open the app, which is the whole problem the feed was
 * built to fix.
 *
 * Tagged per room with `renotify`, so five messages in a row from a sibling are
 * one notification showing the latest rather than five stacked on a lock
 * screen — each still buzzes, but the tray never becomes a transcript.
 */
class FeedMessagePosted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $roomId,
        private readonly string $title,
        private readonly string $body,
        private readonly string $url,
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
            ->tag('feed-room-'.$this->roomId)
            ->renotify()
            ->data(['url' => $this->url]);
    }
}
