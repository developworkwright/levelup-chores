<?php

namespace App\Enums;

/**
 * The stamp sheet — one picture, sent on its own, with no bubble round it.
 *
 * This is the whole feature for Colton, who is six and cannot type a sentence
 * yet. A room he can only talk in by typing is a room he is not in, so a stamp
 * is a first-class message rather than a decoration on one: it posts, it sits
 * in the stream at 44px, and it can be reacted to like anything else.
 *
 * Stored by key rather than by character, so a stamp can be redrawn — swapped
 * for a better emoji, or one day for real artwork — without rewriting every
 * message that ever used it.
 *
 * Twelve, which is two rows of six on a phone and the most a six-year-old will
 * scan before picking the first one again.
 */
enum FeedStamp: string
{
    case Yes = 'yes';
    case No = 'no';
    case Love = 'love';
    case Laugh = 'laugh';
    case Hug = 'hug';
    case HighFive = 'high_five';
    case Hungry = 'hungry';
    case Tired = 'tired';
    case Playing = 'playing';
    case Sorry = 'sorry';
    case Coming = 'coming';
    case Star = 'star';

    public function glyph(): string
    {
        return match ($this) {
            self::Yes => '👍',
            self::No => '👎',
            self::Love => '❤️',
            self::Laugh => '😂',
            self::Hug => '🤗',
            self::HighFive => '🙌',
            self::Hungry => '🍕',
            self::Tired => '😴',
            self::Playing => '🎮',
            self::Sorry => '🥺',
            self::Coming => '🏃',
            self::Star => '⭐',
        };
    }

    /**
     * Read out by a screen reader, and used as the room-list preview when a
     * stamp is the last thing anybody said.
     */
    public function label(): string
    {
        return match ($this) {
            self::Yes => 'Yes',
            self::No => 'No',
            self::Love => 'Love',
            self::Laugh => 'Funny',
            self::Hug => 'Hug',
            self::HighFive => 'High five',
            self::Hungry => 'Hungry',
            self::Tired => 'Tired',
            self::Playing => 'Playing',
            self::Sorry => 'Sorry',
            self::Coming => 'On my way',
            self::Star => 'Nice one',
        };
    }
}
