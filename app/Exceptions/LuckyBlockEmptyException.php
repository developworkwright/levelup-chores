<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Lucky Block is hit with nothing in the pool.
 *
 * The kid's screen hides the block entirely in this state, so this is a guard
 * against a stale page rather than something a kid should ever read — a parent
 * switching the last prize off between render and tap.
 */
class LuckyBlockEmptyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Lucky Block is empty right now.');
    }
}
