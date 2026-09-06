<?php

namespace App\Exceptions;

/**
 * Thrown whenever a cart or checkout operation can't be satisfied by
 * current inventory (out of stock, or lost an optimistic-lock race).
 * Controllers catch this and turn it into a 409 response.
 */
class InsufficientStockException extends \RuntimeException
{
}
