<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientTicketsException extends RuntimeException
{
    public function __construct(public readonly int $shortfall)
    {
        parent::__construct("Not enough tickets — need {$shortfall} more.");
    }
}
