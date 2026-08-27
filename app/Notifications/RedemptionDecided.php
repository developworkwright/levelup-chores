<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to the kid when a reward they cashed out for is handed over, or turned
 * down and refunded.
 *
 * The turned-down half is the one that matters. Points leave the balance the
 * instant a kid asks, and nothing on their side lists their own requests — so
 * a rejection reached them only as a refund line in their stats, if they went
 * looking. This is the app saying it out loud, reason and all.
 *
 * Only lands if the kid has a push subscription; the ledger is the reliable
 * signal.
 */
class RedemptionDecided extends Notification implements ShouldQueue
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
            ->tag('redemption-decided')
            ->renotify()
            ->data(['url' => '/kid/loot']);
    }
}
