<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Events;

use Siberfx\FacebookAutopost\Data\Connection;

final class Connected
{
    public function __construct(public readonly Connection $connection) {}
}
