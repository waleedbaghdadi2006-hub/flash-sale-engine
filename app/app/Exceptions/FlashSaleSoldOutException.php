<?php

namespace App\Exceptions;

use Exception;

class FlashSaleSoldOutException extends Exception
{
    public function __construct(string $message = 'This flash sale item is sold out.')
    {
        parent::__construct($message);
    }
}
