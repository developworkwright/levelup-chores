<?php

namespace App\Enums;

/**
 * The four kinds of room in the family feed.
 *
 * A room has no name of its own — see the feed_rooms migration. It is named by
 * its kind, and where it has one, by the person it is for. That is what keeps
 * the feed from needing any administration at all: there is nothing to create,
 * nothing to rename and nothing to tidy up.
 *
 * Ordered as the room list draws them: the whole house, then the kids, then a
 * kid's line to the grown-ups, then the one-to-ones.
 */
enum FeedRoomKind: string
{
    case Everyone = 'everyone';
    case Kids = 'kids';
    case Parents = 'parents';
    case Direct = 'direct';

    /**
     * The kinds there is exactly one of per household.
     *
     * Parents is not here because there is one per *kid* rather than one per
     * house, and Direct is not here because one is made the first time somebody
     * messages somebody and never before — an empty conversation with every
     * sibling is a list of things nobody has said.
     *
     * @return array<int, self>
     */
    public static function householdWide(): array
    {
        return [self::Everyone, self::Kids];
    }

    /**
     * Whether the room list files this one under ROOMS rather than under
     * JUST YOU TWO. A kid's line to the grown-ups is a room, not a DM: there
     * are three people in it and it is always there.
     */
    public function isGroup(): bool
    {
        return $this !== self::Direct;
    }

    /**
     * The room's picture, where it has one. Null means it is drawn as a
     * monogram instead — a letter, or the grown-ups' initials — because a
     * room about specific people is identified by those people.
     */
    public function glyph(): ?string
    {
        return match ($this) {
            self::Everyone => '🏠',
            self::Kids => '⚔',
            self::Parents, self::Direct => null,
        };
    }
}
