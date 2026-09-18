<?php

namespace App\Enums;

/**
 * How grown up a pet is. It grows one step for every approved chore its kid
 * does while it is the pet out — see App\Services\PetService.
 *
 * Each stage can have a sprite sheet of its own, because a puppy and a dog are
 * different drawings, not one drawing at two sizes. Own art is drawn at its
 * true size already (see CosmeticSlot::PET_FAMILY_PROMPT); a stage borrowing an
 * older stage's sheet is shrunk instead, so growing up still shows.
 */
enum PetStage: string
{
    case Baby = 'baby';
    case Young = 'young';
    case Adult = 'adult';

    /** Chores to reach each stage, counted from the day it was bought. */
    public function startsAt(): int
    {
        return match ($this) {
            self::Baby => 0,
            self::Young => 10,
            self::Adult => 30,
        };
    }

    public static function forGrowth(int $growth): self
    {
        return match (true) {
            $growth >= self::Adult->startsAt() => self::Adult,
            $growth >= self::Young->startsAt() => self::Young,
            default => self::Baby,
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Baby => self::Young,
            self::Young => self::Adult,
            self::Adult => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Baby => 'Baby',
            self::Young => 'Young',
            self::Adult => 'Grown up',
        };
    }

    /**
     * The smallest this age is ever drawn, as a share of the adult's height —
     * the floor CosmeticArt::splitFamily() lifts a tiny drawing to, and the
     * shrink for an age borrowing an older sheet.
     *
     * Deliberately close to the adult. A pet is only 78px at full size, and
     * at the true-to-life 60% a baby was a speck too small to tap or to read
     * as a puppy; the difference in the drawing does the rest of the work.
     */
    public function scale(): float
    {
        return match ($this) {
            self::Baby => 0.8,
            self::Young => 0.9,
            self::Adult => 1.0,
        };
    }

    /** The cosmetics column that holds this stage's own sheet. */
    public function artColumn(): string
    {
        return match ($this) {
            self::Baby => 'baby_art_path',
            self::Young => 'young_art_path',
            self::Adult => 'art_path',
        };
    }

    /**
     * The stages that can be uploaded after the pet exists: the two younger ones.
     *
     * @return array<int, self>
     */
    public static function younger(): array
    {
        return [self::Baby, self::Young];
    }
}
