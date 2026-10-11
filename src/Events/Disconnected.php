<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Events;

final class Disconnected
{
    /** @param  bool  $revoked  Whether Facebook confirmed revoking the token. */
    public function __construct(public readonly bool $revoked) {}
}
