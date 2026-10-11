<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Exceptions;

use RuntimeException;

final class NotConfigured extends RuntimeException
{
    public function __construct(string $message = 'Set FACEBOOK_CLIENT_ID and FACEBOOK_CLIENT_SECRET before connecting Facebook.')
    {
        parent::__construct($message);
    }
}
