<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown internally when a `version`-guarded UPDATE affects zero rows,
 * meaning another request won the race first. Never bubbles up to the
 * controller — OrderService catches it and retries the whole attempt.
 */
class OptimisticLockConflictException extends RuntimeException
{
}
