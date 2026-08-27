<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to the kid when a parent finally looks at work they submitted —
 * signed off, or sent back to do again.
 *
 * The longest wait in the app sits between "Mark it done" and somebody
 * deciding: the points don't move, the board doesn't change, and nothing tells
 * a kid whether they are owed anything or about to be asked to redo it. Both
 * outcomes ride the same tag, so a decision made minutes after another one
 * replaces it in the tray rather than stacking up.
 *
 * Only lands if the kid has a push subscription; their balance and the board
 * are the reliable signals.
 */
class ChoreReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly string $url = '/kid/home',
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
            ->tag('chore-reviewed')
            ->renotify()
            ->data(['url' => $this->url]);
    }
}
