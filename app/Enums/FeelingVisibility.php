<?php

namespace App\Enums;

/**
 * Who may read the *because* — never the feeling itself.
 *
 * The feeling word is always visible to the whole house. That is the half that
 * makes this work at all: everyone answering, everyone seeing, so nobody is
 * being examined on their own. The reason behind it is the half that gets a
 * choice, made per entry rather than as a setting — a kid who has to go and
 * change a setting to be private will never be private, and on a good day he
 * wants to say why anyway.
 *
 * Private is the default and always will be. The safe direction to be wrong in
 * is the one where nothing was shared that the writer didn't mean to share.
 *
 * ## What "private" honestly means today
 *
 * It means the app shows it to nobody: not the house strip, not a parent
 * screen, not an export. It does **not** yet mean the text is unreadable to
 * someone with the database in front of them. Locking an entry with the
 * writer's own PIN — the deliberate act that makes it cryptographically true —
 * is the next piece, and nothing here should ever be worded as a stronger
 * promise than the app can currently keep.
 */
enum FeelingVisibility: string
{
    case Private = 'private';

    case Parents = 'parents';

    case House = 'house';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Just me',
            self::Parents => 'Mom and Dad',
            self::House => 'Everyone',
        };
    }

    /** What picking this actually does, said plainly on the control. */
    public function description(): string
    {
        return match ($this) {
            self::Private => 'Nobody sees why. Only the word shows.',
            self::Parents => 'Mom and Dad can read why.',
            self::House => 'Everyone at home can read why.',
        };
    }

    public function glyph(): string
    {
        return match ($this) {
            self::Private => '🔒',
            self::Parents => '👪',
            self::House => '🏠',
        };
    }
}
