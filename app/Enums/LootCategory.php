<?php

namespace App\Enums;

/**
 * What kind of thing a reward is.
 *
 * The Loot Shop's second browse axis, and the answer to a shop nobody shops
 * in. Sorted by price the catalogue is one long undifferentiated wall — three
 * shelves of name-plus-paragraph — so a kid after "something to eat" has to
 * read every card to find it. Six buckets and a picture each turns that into
 * a glance.
 *
 * A closed set, unlike {@see ChoreIcon}, which is a shortlist over a free-text
 * column. A category is only worth anything if every item lands in one of a
 * handful of piles a kid already recognises; a free-text version drifts into
 * twenty near-duplicates and stops chunking anything.
 *
 * Nullable on the item: a reward nobody has filed yet still has to appear, and
 * it shows under "Everything else" rather than being hidden until someone
 * tidies up.
 */
enum LootCategory: string
{
    case Treats = 'treats';
    case Screen = 'screen';
    case Outings = 'outings';
    case Things = 'things';
    case Time = 'time';
    case Privileges = 'privileges';

    /** The shelf heading. */
    public function label(): string
    {
        return match ($this) {
            self::Treats => 'Treats',
            self::Screen => 'Screen time',
            self::Outings => 'Days out',
            self::Things => 'Stuff',
            self::Time => 'Time with you',
            self::Privileges => 'Privileges',
        };
    }

    /**
     * What the heading looks like to someone who isn't reading it.
     *
     * The whole point of the split: a category a kid has to read the name of
     * has done half its job. Free-set Font Awesome only — a Pro-only name
     * resolves to a blank, which is worse than no icon at all.
     */
    public function faClass(): string
    {
        return match ($this) {
            self::Treats => 'fa-solid fa-ice-cream',
            self::Screen => 'fa-solid fa-gamepad',
            self::Outings => 'fa-solid fa-car-side',
            self::Things => 'fa-solid fa-gift',
            self::Time => 'fa-solid fa-heart',
            self::Privileges => 'fa-solid fa-key',
        };
    }

    /** Kept apart from the accent cycle so a shelf reads as a shelf. */
    public function colorVar(): string
    {
        return match ($this) {
            self::Treats => 'var(--fq-coral)',
            self::Screen => 'var(--fq-violet)',
            self::Outings => 'var(--fq-cyan)',
            self::Things => 'var(--fq-gold)',
            self::Time => 'var(--fq-magenta)',
            self::Privileges => 'var(--fq-lime)',
        };
    }

    /**
     * A one-line hint under the heading, for the grown-up filing things and
     * the older kid who does read.
     */
    public function blurb(): string
    {
        return match ($this) {
            self::Treats => 'Something to eat',
            self::Screen => 'Games, telly, tablet',
            self::Outings => 'Going somewhere',
            self::Things => 'Something to keep',
            self::Time => 'A grown-up all to yourself',
            self::Privileges => 'Being allowed to',
        };
    }

    /**
     * Words that file a reward automatically, tried in order.
     *
     * Same shape and the same trap as ChoreIcon's: matched on whole words, so
     * "cinema" can't be found inside another word and "ice cream" is two
     * tokens rather than a substring. A miss files nothing rather than
     * guessing — an item in the wrong pile is worse than one in "Everything
     * else", because the kid looking for it searches the pile it should be in
     * and concludes it doesn't exist.
     *
     * @return array<string, array<int, string>>
     */
    public static function keywords(): array
    {
        return [
            self::Screen->value => ['screen', 'tv', 'telly', 'tablet', 'ipad', 'xbox', 'playstation', 'switch', 'game', 'games', 'gaming', 'youtube', 'roblox', 'minecraft', 'controller'],
            self::Outings->value => ['cinema', 'movies', 'zoo', 'park', 'swimming', 'bowling', 'trip', 'outing', 'museum', 'beach', 'arcade', 'day out'],
            self::Time->value => ['together', 'one on one', 'date', 'baking', 'cook', 'story', 'stories', 'board game', 'walk'],
            self::Privileges->value => ['stay up', 'bedtime', 'late', 'sleepover', 'skip', 'choose', 'pick', 'first', 'front seat', 'day off'],
            self::Treats->value => ['ice cream', 'sweets', 'candy', 'chocolate', 'pizza', 'snack', 'treat', 'cake', 'biscuit', 'donut', 'milkshake', 'takeaway', 'mcdonalds'],
            // Last: 'toy' and 'book' are specific, but 'thing' and 'money' are
            // broad enough to swallow anything above them.
            self::Things->value => ['toy', 'toys', 'lego', 'book', 'books', 'money', 'cash', 'pounds', 'dollars', 'thing'],
        ];
    }

    /** The pile a reward's name and description put it in, or null. */
    public static function forText(string $text): ?self
    {
        $haystack = mb_strtolower($text);

        foreach (self::keywords() as $category => $words) {
            foreach ($words as $word) {
                if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $haystack) === 1) {
                    return self::from($category);
                }
            }
        }

        return null;
    }
}
