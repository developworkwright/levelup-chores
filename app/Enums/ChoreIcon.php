<?php

namespace App\Enums;

/**
 * The faces a chore card can wear, for the kids who can't read the name yet.
 *
 * **A shortlist, not the whole vocabulary.** `chores.icon` stores a Font
 * Awesome class string — `'fa-solid fa-utensils'` — and a parent can type any
 * class Font Awesome ships. These sixteen cases are the presets the picker
 * offers and the answers the keyword pass below can reach; they are one
 * spelling of an icon class, not the only allowed one, which is why nothing
 * casts that column to this enum.
 *
 * Pictorial icons rather than the app's usual character glyph (badges carry
 * one character, perks four). A character is no more readable to a
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

    /**
     * The Font Awesome class this preset writes into `chores.icon`.
     *
     * Every one of these is in the *free* set. A Pro-only name is worse than a
     * wrong picture: the class resolves, the glyph never ships, and the card
     * comes out blank — the one outcome the fallback chain exists to stop.
     */
    public function faClass(): string
    {
        return match ($this) {
            self::Lawn => 'fa-solid fa-seedling',
            self::Dishes => 'fa-solid fa-utensils',
            self::Laundry => 'fa-solid fa-shirt',
            self::Bed => 'fa-solid fa-bed',
            self::Sweep => 'fa-solid fa-broom',
            self::Pet => 'fa-solid fa-paw',
            self::Trash => 'fa-solid fa-trash-can',
            // No vacuum in the free set; the fan is the nearest thing with a
            // motor in it.
            self::Vacuum => 'fa-solid fa-fan',
            self::Water => 'fa-solid fa-droplet',
            self::Table => 'fa-solid fa-plate-wheat',
            self::Recycle => 'fa-solid fa-recycle',
            self::Teeth => 'fa-solid fa-tooth',
            self::Window => 'fa-solid fa-window-maximize',
            self::Toys => 'fa-solid fa-cubes',
            self::Car => 'fa-solid fa-car',
            self::Mail => 'fa-solid fa-envelope',
        };
    }

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
     * The preset a stored class came from, or null when a parent typed their
     * own.
     *
     * The picker needs this to know which swatch to light up. Nothing else
     * does, because a custom class renders exactly the way a preset does.
     */
    public static function tryFromClass(?string $class): ?self
    {
        if ($class === null) {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($case->faClass() === $class) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Turns whatever a parent typed into a class string safe to render, or
     * null when there is nothing usable in it.
     *
     * Generous on the way in, strict on the way out. Parents copy from
     * fontawesome.com, which hands them a whole `<i class="…"></i>` tag, and
     * they type `fa-rocket` on its own as often as they type a style with it —
     * so a tag is unwrapped, a bare name gets `fa-solid` put in front of it,
     * and whatever survives is reduced to `fa-` tokens.
     *
     * That last step is the security of this, not tidiness: the result is
     * interpolated into a `class` attribute, so only `[a-z0-9-]` tokens
     * beginning `fa` ever reach the markup, whatever was pasted in.
     */
    public static function normalizeClass(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        // A pasted `<i class="fa-solid fa-rocket"></i>` — keep what's quoted
        // and throw the tag away.
        if (preg_match('/class\s*=\s*["\']([^"\']*)["\']/i', $input, $matches) === 1) {
            $input = $matches[1];
        }

        $tokens = preg_split('/[\s,.]+/', mb_strtolower(trim($input)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = array_values(array_unique(array_filter(
            $tokens,
            fn (string $token): bool => preg_match('/^fa[a-z0-9-]*$/', $token) === 1,
        )));

        // Bare `fa-rocket` is what most people type, and a style on its own is
        // a font weight with no glyph behind it — both need spotting.
        $styles = ['fa', 'fas', 'far', 'fab', 'fa-solid', 'fa-regular', 'fa-brands', 'fa-classic'];
        $named = array_values(array_filter(
            array_diff($tokens, $styles),
            // A name needs something after the dash. `fa-` on its own passes
            // the token filter above and is not a style, so without this it
            // comes out as the class `fa-solid fa-` — a face with no glyph
            // behind it. It is also what a half-typed class looks like at
            // every keystroke, which the live previews render.
            fn (string $token): bool => preg_match('/^fa-[a-z0-9]/', $token) === 1,
        ));

        if ($named === []) {
            return null;
        }

        $style = array_values(array_intersect($tokens, $styles))[0] ?? 'fa-solid';

        $class = $style.' '.implode(' ', $named);

        // The column holds 64. A class longer than that is a paste accident,
        // and storing a truncated one would put a face on the card that
        // nobody chose.
        return mb_strlen($class) <= 64 ? $class : null;
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

    /**
     * The class a chore's name suggests, or null when nothing fits.
     *
     * The form every caller actually wants — `chores.icon` holds classes, not
     * keys, so the enum case in between is an implementation detail.
     */
    public static function classForName(string $name): ?string
    {
        return self::forName($name)?->faClass();
    }
}
