<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to every kid in the household when a parent puts something new in the
 * shop.
 *
 * The same problem the count badge on the Spend tab was added for: the shop is
 * a long shelf, nobody reads it end to end, and a restock was invisible. The
 * badge catches a kid who opens the app; this catches the one who doesn't,
 * which is the whole reason a parent adds a reward worth saving for.
 *
 * Announced to every kid whatever their level, exactly as the badge counts it —
 * a locked reward is a thing to climb towards, and a push that skipped it would
 * leave a badge on the tab with nothing behind it.
 */
class LootRestocked extends Notification implements ShouldQueue
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
            ->tag('loot-restocked')
            ->renotify()
            ->data(['url' => '/kid/loot']);
    }
}
