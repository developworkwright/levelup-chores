<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A sibling offer was answered, withdrawn or lapsed before this action landed —
 * or the kid taking the action is not the one it belongs to.
 */
class OfferUnavailableException extends RuntimeException {}
