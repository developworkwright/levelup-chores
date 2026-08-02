<?php

namespace App\Enums;

/**
 * Which side of the trade the kid who *sent* the offer is on.
 */
enum SiblingOfferKind: string
{
    /** Sender pays, recipient does the favour. Escrowed at offer time. */
    case Paying = 'paying';

    /** Sender does the favour, recipient pays. Nothing to escrow. */
    case Earning = 'earning';

    /** Written from the sender's point of view, matching the compose card's toggle. */
    public function label(): string
    {
        return match ($this) {
            self::Paying => "I'll pay them",
            self::Earning => 'They pay me',
        };
    }

    /** Written from the recipient's point of view, for the incoming offer card. */
    public function incomingLabel(): string
    {
        return match ($this) {
            self::Paying => 'They pay you',
            self::Earning => 'You pay them',
        };
    }
}
