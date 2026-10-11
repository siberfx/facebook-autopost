<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Exceptions;

use RuntimeException;

final class NotShareable extends RuntimeException
{
    public function __construct(string $message = 'This item is not live, so it cannot be shared on Facebook.')
    {
        parent::__construct($message);
    }
}
