<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Sent to every kid in the household when a parent adds a song.
 *
 * The music picker has the same problem the loot shelf does, only worse: it is
 * a menu behind a button in the header, so a kid who has already picked a
 * favourite has no reason to ever open it again. A song nobody opens the menu
 * to find is a song nobody hears.
 *
 * The title is in the body rather than left as "something new", because the
 * whole of what is new here *is* the name — there is no page to go and look at,
 * just a line on a menu.
 *
 * @see \App\Notifications\LootRestocked, which this deliberately mirrors.
 */
class NewSongAdded extends Notification implements ShouldQueue
{
    use Queueable;

    /** The whole sentence, built by MusicService: one song by name, or a count. */
    public function __construct(private readonly string $announcement) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('New music!')
            ->icon('/icons/icon-192.png')
            ->body($this->announcement.' — tap the ♫ in the header.')
            ->tag('music-added')
            ->renotify()
            // No page of its own, so it opens the one a kid starts their day
            // on. The button that plays it is in the header of every kid page
            // anyway, this one included.
            ->data(['url' => '/kid/home']);
    }
}
