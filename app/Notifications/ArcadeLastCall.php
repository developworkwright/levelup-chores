<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The Sunday-evening nudge, a few hours before bedtime, telling a kid what they
 * are about to win or lose when the arcade week closes.
 *
 * The prize has always been paid on a deadline nobody could see. This is the
 * half of fixing that which reaches a kid who is not in the app — the board's
 * own countdown only works on somebody already looking at it.
 *
 * Timed off bedtime rather than off the end of the week, and worded to match:
 * the boards technically close at midnight, and a push that says so is telling
 * a child to be awake at midnight. See ArcadeService::lastCallHourFor().
 */
class ArcadeLastCall extends Notification implements ShouldQueue
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
            ->tag('arcade-last-call')
            ->renotify()
            ->data(['url' => '/kid/arcade']);
    }
}
