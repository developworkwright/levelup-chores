<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientTokensException extends RuntimeException
{
    public function __construct(public readonly int $shortfall)
    {
        parent::__construct("Not enough tokens — need {$shortfall} more.");
    }
}
