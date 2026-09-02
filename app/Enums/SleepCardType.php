<?php

namespace App\Enums;

/**
 * Which bedtime card a kid gets.
 *
 * A progression rather than a preference: the own-bed card is for a kid still
 * learning to stay put, and the hours card is what they move onto once that
 * has stopped being the hard part. A parent switches the type from the kid
 * console the day it stops being the right question.
 *
 * Each type owns its own counters — see the hours-card migration for why they
 * are not shared. The column names live here so the service can branch on the
 * type rather than on a string in five places.
 */
enum SleepCardType: string
{
    case OwnBed = 'own_bed';

    case Hours = 'hours';

    public function label(): string
    {
        return match ($this) {
            self::OwnBed => 'Own Bed Card',
            self::Hours => 'Hours Card',
        };
    }

    /** What the card is asking about, for the console's one-line explanation. */
    public function description(): string
    {
        return match ($this) {
            self::OwnBed => 'Did they stay in their own bed?',
            self::Hours => 'How many hours did they sleep?',
        };
    }

    /** The profile column holding nights that counted. */
    public function nightsColumn(): string
    {
        return match ($this) {
            self::OwnBed => 'sleep_nights',
            self::Hours => 'sleep_hours_nights',
        };
    }

    public function runColumn(): string
    {
        return match ($this) {
            self::OwnBed => 'sleep_run',
            self::Hours => 'sleep_hours_run',
        };
    }

    public function bestRunColumn(): string
    {
        return match ($this) {
            self::OwnBed => 'sleep_best_run',
            self::Hours => 'sleep_hours_best_run',
        };
    }

    public function runPaidThroughColumn(): string
    {
        return match ($this) {
            self::OwnBed => 'sleep_run_paid_through',
            self::Hours => 'sleep_hours_run_paid_through',
        };
    }

    public function pendingChestColumn(): string
    {
        return match ($this) {
            self::OwnBed => 'pending_sleep_chest',
            self::Hours => 'pending_sleep_hours_chest',
        };
    }

    /** Only the own-bed card draws a sky. */
    public function hasConstellations(): bool
    {
        return $this === self::OwnBed;
    }
}
