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
     * How big a pet of this age is on screen, in CSS pixels — the box one pose
     * is drawn in. THE place to change how big pets are.
     *
     * Fixed numbers, not ratios worked out from the art: generators draw each
     * age at whatever size they like, so every age's sheet is cut at the same
     * full size (see CosmeticArt::splitFamily()) and it is these three numbers
     * alone that make a baby small.
     *
     * These are phone sizes. Past a 900px-wide window the pet layer grows them
     * further, up to 1.6 times — see screenZoom() in pets.js.
     */
    public function pixels(): int
    {
        return match ($this) {
            self::Baby => 80,
            self::Young => 100,
            self::Adult => 120,
        };
    }

    /**
     * The box the pet layer draws one pose in, before any scaling —
     * PET_SIZE in resources/js/pets.js. The two must agree.
     */
    public const ENGINE_BOX = 78;

    /** pixels() as a scale on the pet layer's box, which is what it takes. */
    public function scale(): float
    {
        return round($this->pixels() / self::ENGINE_BOX, 3);
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
