<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A bounty was taken, settled, withdrawn or lapsed before this action landed —
 * or the profile taking the action is not the side of the deal it belongs to.
 */
class BountyUnavailableException extends RuntimeException {}
