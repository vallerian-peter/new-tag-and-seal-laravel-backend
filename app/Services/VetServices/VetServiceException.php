<?php

namespace App\Services\VetServices;

use RuntimeException;

class VetServiceException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $errorCode = 'ERROR')
    {
        parent::__construct($message);
    }
}
