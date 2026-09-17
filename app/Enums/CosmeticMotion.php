<?php

namespace App\Enums;

/**
 * The motions every item shares — `FQCosmetics.MOTION` in cosmetics.js.
 *
 * Spin has a fast twin, so there are ten keys over nine keyframes. A motion is
 * one word on an item's row; the keyframes ship once, in app.css.
 */
enum CosmeticMotion: string
{
    case Spin = 'spin';
    case SpinFast = 'spinfast';
    case Tick = 'tick';
    case Throb = 'throb';
    case Bob = 'bob';
    case Sway = 'sway';
    case Blink = 'blink';
    case Flicker = 'flicker';
    case Radiate = 'radiate';
    case Drift = 'drift';

    public function label(): string
    {
        return match ($this) {
            self::Spin => 'Spin — slow, 14s',
            self::SpinFast => 'Spin — fast, 6s',
            self::Tick => 'Tick — steps round, 9s',
            self::Throb => 'Throb — 2.4s',
            self::Bob => 'Bob — 2.9s',
            self::Sway => 'Sway — 3.6s',
            self::Blink => 'Blink — 4.2s',
            self::Flicker => 'Flicker — like a candle',
            self::Radiate => 'Radiate — outward, 1.9s',
            self::Drift => 'Drift — backgrounds, 26s',
        };
    }

    /**
     * The CSS `animation` shorthand, for the one place the app moves a thing
     * the recipe code never drew: an uploaded picture.
     */
    public function css(): string
    {
        return match ($this) {
            self::Spin => 'fqspin 14s linear infinite',
            self::SpinFast => 'fqspin 6s linear infinite',
            self::Tick => 'fqtick 9s steps(12) infinite',
            self::Throb => 'fqthrob 2.4s ease-in-out infinite',
            self::Bob => 'fqbob 2.9s ease-in-out infinite',
            self::Sway => 'fqsway 3.6s ease-in-out infinite',
            self::Blink => 'fqblink 4.2s ease-in-out infinite',
            self::Flicker => 'fqflicker 4.2s steps(1,end) infinite',
            self::Radiate => 'fqradiate 1.9s ease-out infinite',
            self::Drift => 'fqdrift 26s linear infinite',
        };
    }
}
