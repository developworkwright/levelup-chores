<?php

namespace App\Enums;

/**
 * What a row in `feed_messages` actually is.
 *
 * Four of these are a person talking. The fifth, {@see self::Event}, is the app
 * talking, and it is drawn deliberately smaller than everything else — see the
 * feed_messages migration for why the machine must never be the loudest voice
 * in a room the kids are trying to use.
 */
enum FeedMessageKind: string
{
    case Text = 'text';
    case Stamp = 'stamp';
    case Drawing = 'drawing';
    case Shoutout = 'shoutout';
    case Event = 'event';

    /**
     * A quote a grown-up wrote down, relayed into the room it belongs in.
     *
     * It used to be a card at the bottom of Home, which is the last place
     * anybody scrolls to, and it arrived by push besides. Neither was right:
     * a thing your brother said out loud is conversation, and conversation
     * belongs where the conversation is.
     *
     * The row is a pointer — `source` is the Quote — so the feed renders the
     * same `<x-quote-line>` the Journal does, with the *same* reactions
     * underneath it. There is one quote and one set of faces on it, whichever
     * screen you are looking at.
     */
    case Quote = 'quote';

    /**
     * Whether this kind carries an avatar, a name and a reaction row.
     *
     * An event doesn't: it is one mono line indented to the text column with
     * nothing to tap, because congratulating somebody is the app's job and
     * inviting three siblings to applaud it would turn a room into a feed.
     *
     * A quote doesn't either, for a different reason — it carries its own card,
     * its own attribution and its own reactions, and a second name and a second
     * reaction row above them would be the app taking credit for the joke.
     */
    public function isFromAPerson(): bool
    {
        return $this !== self::Event && $this !== self::Quote;
    }

    /** The one-line preview the room list shows, where the body isn't it. */
    public function previewVerb(): ?string
    {
        return match ($this) {
            self::Stamp => 'sent a stamp',
            self::Drawing => 'sent a drawing',
            self::Text, self::Shoutout, self::Event, self::Quote => null,
        };
    }
}
