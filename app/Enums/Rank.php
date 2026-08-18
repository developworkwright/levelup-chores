<?php

namespace App\Enums;

/**
 * The title a kid wears, taken straight off their level.
 *
 * A level number is a fact; a rank is something you can be. Kids don't compare
 * "16 against 12" — they compare Bonebreaker against Nightblade, which is why
 * the rank and not the number is what shows on the login tiles where they see
 * each other. Every fifth level moves you up, so a rank is roughly a fortnight
 * of work rather than a day of it.
 *
 * The ladder runs to 95 because the kid furthest along was already past 40
 * when it was built, and a top rank you have already reached is a ceiling
 * rather than a goal. Doomlord sits at the end of it, not in the middle.
 *
 * Names lean menacing on purpose, and no rank is a put-down: the kid at the
 * bottom of the ladder is a Prowler, not a nobody.
 */
enum Rank: string
{
    case Prowler = 'prowler';
    case Nightblade = 'nightblade';
    case Bonebreaker = 'bonebreaker';
    case Dreadhunter = 'dreadhunter';
    case Grimwarden = 'grimwarden';
    case Voidreaver = 'voidreaver';
    case Hellfang = 'hellfang';
    case Ruinbringer = 'ruinbringer';
    case Soulreaper = 'soulreaper';
    case Deathsinger = 'deathsinger';
    case Shadowtyrant = 'shadowtyrant';
    case Worldbreaker = 'worldbreaker';
    case Starflayer = 'starflayer';
    case Voidtyrant = 'voidtyrant';
    case Fatespinner = 'fatespinner';
    case Skyrender = 'skyrender';
    case Suneater = 'suneater';
    case Endbringer = 'endbringer';
    case Deathless = 'deathless';
    case Doomlord = 'doomlord';

    /** Levels per rank. Every fifth level is the one that changes your title. */
    public const LEVELS_PER_RANK = 5;

    /**
     * The lowest level that wears this title.
     *
     * Cases are declared in this order and `next()` walks `cases()`, so a new
     * rank has to be inserted in the right place rather than appended.
     */
    public function minLevel(): int
    {
        return match ($this) {
            self::Prowler => 1,
            self::Nightblade => 5,
            self::Bonebreaker => 10,
            self::Dreadhunter => 15,
            self::Grimwarden => 20,
            self::Voidreaver => 25,
            self::Hellfang => 30,
            self::Ruinbringer => 35,
            self::Soulreaper => 40,
            self::Deathsinger => 45,
            self::Shadowtyrant => 50,
            self::Worldbreaker => 55,
            self::Starflayer => 60,
            self::Voidtyrant => 65,
            self::Fatespinner => 70,
            self::Skyrender => 75,
            self::Suneater => 80,
            self::Endbringer => 85,
            self::Deathless => 90,
            self::Doomlord => 95,
        };
    }

    public static function fromLevel(int $level): self
    {
        $rank = self::Prowler;

        foreach (self::cases() as $case) {
            if ($level >= $case->minLevel()) {
                $rank = $case;
            }
        }

        return $rank;
    }

    /**
     * How many rank boundaries sit between two levels. A kid handed a pile of
     * XP at once can clear more than one, and both the payout and the card
     * announcing it have to agree on how many.
     */
    public static function countBetween(int $fromLevel, int $toLevel): int
    {
        $crossed = 0;

        foreach (self::cases() as $rank) {
            if ($rank->minLevel() > $fromLevel && $rank->minLevel() <= $toLevel) {
                $crossed++;
            }
        }

        return $crossed;
    }

    /**
     * The rank above this one, or null at the top. Doomlord is deliberately
     * open-ended rather than a wall — levels keep coming, the title just stops
     * changing.
     */
    public function next(): ?self
    {
        $cases = self::cases();
        $index = array_search($this, $cases, true);

        return $cases[$index + 1] ?? null;
    }

    /** The level a kid has to reach for their title to change again. */
    public function nextLevel(): ?int
    {
        return $this->next()?->minLevel();
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The flat colour the chip, border and title text take (see tokens.css). */
    public function ringVar(): string
    {
        return "var(--fq-rank-{$this->value}-ring)";
    }

    /** The fill behind the rank chip. */
    public function fillVar(): string
    {
        return "var(--fq-rank-{$this->value}-fill)";
    }

    public function glowVar(): string
    {
        return "var(--fq-rank-{$this->value}-glow)";
    }

    /**
     * Whether this rank's colours move. Only the top one does — a login screen
     * where every tile shimmered would read as noise rather than as a reward.
     */
    public function isAnimated(): bool
    {
        return $this === self::Doomlord;
    }
}
