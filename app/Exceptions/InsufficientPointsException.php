<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientPointsException extends RuntimeException
{
    public function __construct(public readonly int $shortfall)
    {
        parent::__construct("Not enough points — need {$shortfall} more.");
    }
}
