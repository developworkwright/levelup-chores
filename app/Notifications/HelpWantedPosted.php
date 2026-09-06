<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to every kid in the household when a parent flags a chore Help Wanted.
 *
 * Same reasoning as {@see ChoreClosingSoon}: a flag is a parent asking for
 * something, and an ask nobody hears about until they next happen to open the
 * board is just a differently-coloured row. It fires once, at the moment the
 * flag goes on, and carries the bonus ticket in the body — the flag's own
 * pulling power is the reward, so leaving it out would bury the reason to go.
 *
 * Its own class rather than a second title/body through ChoreClosingSoon: the
 * push tag is what lets a phone replace a stale notification with a fresh one,
 * and a help-wanted flag must not overwrite a running countdown.
 */
class HelpWantedPosted extends Notification implements ShouldQueue
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
            ->tag('help-wanted')
            ->renotify()
            ->data(['url' => '/kid/quests']);
    }
}
