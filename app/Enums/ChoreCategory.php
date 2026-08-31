<?php

namespace App\Enums;

use App\Models\Chore;

/**
 * The kinds of job a kid browses for, on the side-quest board's chip row.
 *
 * **Its own vocabulary, not a collection of icon types.** This was briefly
 * derived from `chores.icon` and that was a mistake: the icon is a *card face*,
 * chosen so a kid who can't read the name can still pick the chore, and the
 * column is deliberately uncast so a parent can paste any Font Awesome class.
 * Reading a category off it made the icon picker quietly do two jobs — a parent
 * choosing a nicer face could move a chore to another chip, or off every chip
 * at once. Nothing here touches the icon any more.
 *
 * So `chores.category` is a column a parent sets, and null means nobody has
 * said. Those chores collect under `Other`, which exists so that picking a chip
 * can never make a chore disappear off the board entirely.
 *
 * `Outside` sits among these rather than beside them as its own switch, which
 * makes it exclusive: a chore is Outside *or* it is Garden, not both. That is
 * the point. It used to be guessed from the icon — lawn, bins, car, plants,
 * windows, post were "outdoor by name" — and a guess is exactly what a parent
 * is better placed to make. Weed whacking is outside; so is fetching the post;
 * whether either is worth filing under Garden or Errands instead is a judgement
 * about this household's board.
 *
 * Declaration order is chip order, so the row doesn't reshuffle itself as the
 * board's contents change through the day.
 */
enum ChoreCategory: string
{
    case Outside = 'outside';
    case MyRoom = 'my-room';
    case Kitchen = 'kitchen';
    case Cleaning = 'cleaning';
    case Laundry = 'laundry';
    case Bins = 'bins';
    case Garden = 'garden';
    case Car = 'car';
    case Pets = 'pets';
    case Errands = 'errands';
    case Other = 'other';

    /** What the chip reads. */
    public function label(): string
    {
        return match ($this) {
            self::Outside => 'Outside',
            self::MyRoom => 'My room',
            self::Kitchen => 'Kitchen',
            self::Cleaning => 'Cleaning',
            self::Laundry => 'Laundry',
            self::Bins => 'Bins',
            self::Garden => 'Garden',
            self::Car => 'Car',
            self::Pets => 'Pets',
            self::Errands => 'Errands',
            self::Other => 'Other',
        };
    }

    /**
     * The chip's face.
     *
     * Every one of these is in the Font Awesome *free* set, for the same reason
     * `ChoreIcon::faClass()` says so: a Pro-only name resolves, never ships a
     * glyph, and comes out blank. Unrelated to whatever face the chores inside
     * the category happen to be wearing — see the note above.
     */
    public function faClass(): string
    {
        return match ($this) {
            self::Outside => 'fa-solid fa-sun',
            self::MyRoom => 'fa-solid fa-bed',
            self::Kitchen => 'fa-solid fa-utensils',
            self::Cleaning => 'fa-solid fa-broom',
            self::Laundry => 'fa-solid fa-shirt',
            self::Bins => 'fa-solid fa-trash-can',
            self::Garden => 'fa-solid fa-seedling',
            self::Car => 'fa-solid fa-car',
            self::Pets => 'fa-solid fa-paw',
            self::Errands => 'fa-solid fa-envelope',
            self::Other => 'fa-solid fa-shapes',
        };
    }

    /**
     * The category a chore browses under.
     *
     * One line, and it is the only place that says what an unset category
     * means — so a chore nobody has filed is reachable under Other rather than
     * quietly absent from every chip.
     */
    public static function forChore(Chore $chore): self
    {
        return $chore->category ?? self::Other;
    }
}
