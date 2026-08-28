<?php

namespace App\Notifications;

use App\Models\Profile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to the whole household when a grown-up writes a quote down — every kid,
 * and every parent except the one who typed it.
 *
 * Every kid, including whoever said it: being told the funny thing you said got
 * written down is most of the reward, and a kid who was quoted while out of the
 * room would otherwise never find out. Parents too, because a house has more
 * than one grown-up and only one of them was there to hear it.
 *
 * The quote itself rides in the body rather than a "something was added" nudge:
 * this is the one notification in the app whose whole content fits in the
 * banner, and a push that made a kid open the app to read one line would be
 * worse than the line.
 */
class QuoteAdded extends Notification implements ShouldQueue
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
            // Deliberately untagged. Two quotes in one evening are two
            // different jokes, and a shared tag would replace the first with
            // the second before anyone had read it.
            ->data(['url' => $this->urlFor($notifiable)]);
    }

    /**
     * Where tapping it lands.
     *
     * It has to branch on the role: the kid page is behind `role:kid`, so
     * sending the other parent to the anchored card on kid Home would open a
     * 403 rather than the quote they were just told about.
     *
     * The kid link is anchored because the card sits at the bottom of Home —
     * a kid told about a quote should arrive on it rather than at the top of
     * their day with a scroll ahead of them.
     */
    private function urlFor(object $notifiable): string
    {
        return $notifiable instanceof Profile && $notifiable->isParent()
            ? '/parent/quotes'
            : '/kid/home#quote-of-the-day';
    }
}
