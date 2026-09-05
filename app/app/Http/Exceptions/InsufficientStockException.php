<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an order can't be created because there isn't enough stock
 * (or, for flash sales, enough remaining quantity_limit) to satisfy the
 * requested quantity. Controllers catch this and turn it into a 409.
 */
class InsufficientStockException extends RuntimeException
{
}
