<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a kid asks for a reward their level doesn't reach yet. Carries
 * the required level rather than a shortfall: "you need level 20" is a target
 * to aim at, where "you need 4 more levels" is only a complaint.
 */
class LevelTooLowException extends RuntimeException
{
    public function __construct(public readonly int $requiredLevel)
    {
        parent::__construct("Locked until level {$requiredLevel}.");
    }
}
