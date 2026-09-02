<?php

namespace App\Enums;

/**
 * How today was, in words.
 *
 * ## Words rather than a number
 *
 * This was very nearly a 1–10 dial, and a dial would have been the wrong tool.
 * Anything measurable gets managed: a kid learns what a 6 buys him and answers
 * with the number instead of the feeling. Words can't be optimised the same
 * way, and being able to say *flat* or *fed up* rather than "bad" is itself the
 * useful part — naming a feeling precisely is most of what regulating it takes.
 *
 * ## Nothing here is a good or a bad answer
 *
 * The colors are deliberately not a traffic light. A grid where happy is green
 * and sad is red is a scoreboard, and a kid reading it learns which answers
 * please the room — which is the exact opposite of the point. The hues below
 * separate the words so they're easy to tell apart at a glance and say nothing
 * about which one anybody would prefer you picked.
 *
 * The unglamorous middle — okay, tired, flat — earns its place for the same
 * reason. Most days are one of those, and a list of only peaks and troughs
 * teaches that a feeling has to be dramatic before it counts.
 */
enum Feeling: string
{
    case Happy = 'happy';
    case Excited = 'excited';
    case Calm = 'calm';
    case Proud = 'proud';
    case Okay = 'okay';
    case Tired = 'tired';
    case Flat = 'flat';
    case Nervous = 'nervous';
    case Worried = 'worried';
    case Sad = 'sad';
    case Angry = 'angry';
    case FedUp = 'fed_up';

    /**
     * The opt-out, and it is a first-class answer rather than a blank.
     *
     * Without it, declining reads as not having done your homework, and a kid
     * who doesn't want to say picks a feeling to avoid the awkwardness — which
     * is the mask going up by a different door. It shows in the house strip
     * like any other answer, because "not saying" is a real thing to be today.
     */
    case NotSaying = 'not_saying';

    public function label(): string
    {
        return match ($this) {
            self::Happy => 'Happy',
            self::Excited => 'Excited',
            self::Calm => 'Calm',
            self::Proud => 'Proud',
            self::Okay => 'Okay',
            self::Tired => 'Tired',
            self::Flat => 'Flat',
            self::Nervous => 'Nervous',
            self::Worried => 'Worried',
            self::Sad => 'Sad',
            self::Angry => 'Angry',
            self::FedUp => 'Fed up',
            self::NotSaying => 'Not saying',
        };
    }

    /** How the sentence stem reads: "Today I felt ___ because…" */
    public function stem(): string
    {
        return match ($this) {
            self::NotSaying => 'Today I would rather not say',
            default => 'Today I felt '.mb_strtolower($this->label()),
        };
    }

    public function glyph(): string
    {
        return match ($this) {
            self::Happy => '😊',
            self::Excited => '⚡',
            self::Calm => '🌊',
            self::Proud => '✨',
            self::Okay => '🙂',
            self::Tired => '🥱',
            self::Flat => '➖',
            self::Nervous => '🦋',
            self::Worried => '🌀',
            self::Sad => '💧',
            self::Angry => '🔥',
            self::FedUp => '😤',
            self::NotSaying => '🤐',
        };
    }

    /** Hue for telling them apart. Explicitly not a verdict — see the class docblock. */
    public function cssVar(): string
    {
        return match ($this) {
            self::Happy => 'var(--fq-gold)',
            self::Excited => 'var(--fq-magenta)',
            self::Calm => 'var(--fq-cyan)',
            self::Proud => 'var(--fq-lime)',
            self::Okay => 'var(--fq-green)',
            self::Tired => 'var(--fq-violet)',
            self::Flat => 'var(--fq-text-4)',
            self::Nervous => 'var(--fq-blue)',
            self::Worried => 'var(--fq-violet)',
            self::Sad => 'var(--fq-blue)',
            self::Angry => 'var(--fq-coral)',
            self::FedUp => 'var(--fq-streak)',
            self::NotSaying => 'var(--fq-text-4)',
        };
    }

    /**
     * Everything except the opt-out, which the card draws on its own row.
     *
     * @return array<int, self>
     */
    public static function feelings(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $feeling) => $feeling !== self::NotSaying,
        ));
    }
}
