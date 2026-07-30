<?php

namespace App\Enums;

enum AccentColor: string
{
    case Lime = 'lime';
    case Cyan = 'cyan';
    case Gold = 'gold';
    case Magenta = 'magenta';
    case Coral = 'coral';
    case Violet = 'violet';
    case Parent = 'parent';

    /** CSS custom property (defined in resources/css/tokens.css) carrying this accent's color. */
    public function cssVar(): string
    {
        return match ($this) {
            self::Parent => 'var(--fq-parent)',
            default => "var(--fq-{$this->value})",
        };
    }
}
