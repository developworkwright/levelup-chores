<?php

namespace App\Enums;

/**
 * The faces a chore card can wear, for the kids who can't read the name yet.
 *
 * **Keys, never characters.** `chores.icon` stores `'dishes'`, not '🍽'. A key
 * lets the drawing be redrawn — or the whole set restyled — without a data
 * migration, and it is the reason this is an enum rather than a string column
 * full of glyphs.
 *
 * Pictorial line icons rather than the app's usual character glyph (badges
 * carry one character, perks four). A character is no more readable to a
 * six-year-old than the chore's name, which is the entire reason the face
 * exists.
 */
enum ChoreIcon: string
{
    case Lawn = 'lawn';
    case Dishes = 'dishes';
    case Laundry = 'laundry';
    case Bed = 'bed';
    case Sweep = 'sweep';
    case Pet = 'pet';
    case Trash = 'trash';
    case Vacuum = 'vacuum';
    case Water = 'water';
    case Table = 'table';
    case Recycle = 'recycle';
    case Teeth = 'teeth';
    case Window = 'window';
    case Toys = 'toys';
    case Car = 'car';
    case Mail = 'mail';

    /** What a parent sees under each icon in the picker. */
    public function label(): string
    {
        return match ($this) {
            self::Lawn => 'Lawn',
            self::Dishes => 'Dishes',
            self::Laundry => 'Laundry',
            self::Bed => 'Bed',
            self::Sweep => 'Sweep',
            self::Pet => 'Pets',
            self::Trash => 'Bins',
            self::Vacuum => 'Vacuum',
            self::Water => 'Plants',
            self::Table => 'Table',
            self::Recycle => 'Recycling',
            self::Teeth => 'Teeth',
            self::Window => 'Windows',
            self::Toys => 'Toys',
            self::Car => 'Car',
            self::Mail => 'Post',
        };
    }

    /**
     * Words that pick an icon, in the order they are tried.
     *
     * Order matters where two lists overlap: 'floor' belongs to Sweep, but a
     * chore called "wash the kitchen floor" hits 'wash' first and would come
     * out as laundry — so the narrower, more literal words are checked ahead
     * of the broad ones. Anything unmatched gets no icon at all rather than a
     * wrong one; the card falls back to its typographic face.
     *
     * @return array<string, array<int, string>>
     */
    public static function keywords(): array
    {
        return [
            self::Lawn->value => ['lawn', 'lawns', 'grass', 'garden', 'mow', 'mowing'],
            self::Dishes->value => ['dish', 'dishes', 'dishwasher', 'kitchen', 'plate', 'plates'],
            self::Bed->value => ['bed', 'beds', 'bedroom'],
            self::Sweep->value => ['sweep', 'sweeping', 'broom', 'floor', 'floors', 'mop'],
            self::Pet->value => ['dog', 'dogs', 'cat', 'cats', 'pet', 'pets', 'feed', 'litter'],
            self::Trash->value => ['bin', 'bins', 'trash', 'rubbish', 'garbage'],
            self::Vacuum->value => ['vacuum', 'vacuuming', 'hoover'],
            self::Water->value => ['water', 'watering', 'plant', 'plants'],
            self::Recycle->value => ['recycle', 'recycling', 'recyclables'],
            self::Teeth->value => ['teeth', 'tooth', 'brush'],
            self::Window->value => ['window', 'windows', 'glass'],
            self::Toys->value => ['toy', 'toys', 'lego'],
            self::Car->value => ['car', 'cars', 'garage'],
            self::Mail->value => ['post', 'mail', 'letter', 'letters'],
            self::Table->value => ['table', 'tidy'],
            // Last: 'wash' and 'fold' are broad enough to swallow half the
            // board, so everything more specific gets first refusal.
            self::Laundry->value => ['laundry', 'fold', 'folding', 'wash', 'clothes'],
        ];
    }

    /**
     * The icon a chore's name suggests, or null when nothing fits.
     *
     * Null on purpose: a wrong picture is worse than none, because a card is
     * chosen off the face and a kid who can't read the name has nothing to
     * check it against. The card falls back to its typographic face instead.
     */
    public static function forName(string $name): ?self
    {
        $haystack = mb_strtolower($name);

        foreach (self::keywords() as $icon => $words) {
            foreach ($words as $word) {
                // Whole words, both ends. A plain substring test put a paw
                // print on "Clean the carpet" ('carpet' contains 'pet'), and
                // anchoring only the left-hand end doesn't fix it — there *is*
                // a word boundary before the 'car' in 'carpet'. So the words
                // are matched entire and the plurals and stems are spelled out
                // above rather than inferred. A wrong picture is worse than
                // none on a card whose whole job is to be chosen by a kid who
                // can't read the name beneath it.
                if (preg_match('/\b'.preg_quote($word, '/').'\b/u', $haystack) === 1) {
                    return self::from($icon);
                }
            }
        }

        return null;
    }
}
