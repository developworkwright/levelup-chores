<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to every kid in the household when a parent puts a deadline on a chore.
 *
 * The whole point of a deadline is the race — a countdown nobody is told about
 * is just a chore quietly vanishing — so this fires the moment it's set rather
 * than at some interval before it lands. Only reaches kids who have a push
 * subscription; the countdown on the board is the reliable signal.
 */
class ChoreClosingSoon extends Notification implements ShouldQueue
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
            ->tag('chore-closing')
            ->renotify()
            ->data(['url' => '/kid/quests']);
    }
}
