<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to every kid in the household when a new game goes into the arcade.
 *
 * The same problem `LootRestocked` was written for, one page over, and worse:
 * nothing about the arcade ever comes looking for anybody. A chore nags, a
 * sibling's swap arrives, a monster loses health — the arcade sits there being
 * a thing you have to remember. A second game added quietly is a second
 * game nobody plays.
 *
 * Kids only. A grown-up can already see the switcher on their own console, and
 * they are the ones who put the game there.
 */
class ArcadeGameAdded extends Notification implements ShouldQueue
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
            ->tag('arcade-game-added')
            ->renotify()
            ->data(['url' => '/kid/arcade']);
    }
}
