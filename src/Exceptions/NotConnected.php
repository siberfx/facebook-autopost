<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Exceptions;

use RuntimeException;

final class NotConnected extends RuntimeException
{
    public function __construct(string $message = 'Facebook is not connected.')
    {
        parent::__construct($message);
    }
}
