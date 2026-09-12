<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to whoever was top of a game's weekly board when somebody knocks them
 * off it.
 *
 * The arcade's problem is the one ArcadeGameAdded names: nothing about it ever
 * comes looking for anybody, so a board only exists while a kid happens to be
 * staring at it. Beating a sibling's score is the most interesting thing that
 * happens in this app and the sibling currently finds out days later, if at
 * all — by which point it is a fact rather than a challenge.
 *
 * Deliberately sent to the loser rather than the winner. The winner watched it
 * happen on the screen in front of them; the person with a reason to open the
 * app is the one who just lost something.
 *
 * Sent to grown-ups too. A parent can top a board — they just cannot be paid
 * for it — and being quietly dethroned without being told is the same
 * non-event for them as for anyone else.
 */
class ArcadeLeadLost extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $title,
        private readonly string $body,
        private readonly string $game,
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
            // Tagged per game so a busy evening on two different boards does
            // not have one cabinet's news quietly replacing the other's.
            ->tag('arcade-lead-lost-'.$this->game)
            ->renotify()
            ->data(['url' => '/kid/arcade']);
    }
}
