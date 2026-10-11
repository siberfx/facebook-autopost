<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Events;

use Illuminate\Database\Eloquent\Model;
use Siberfx\FacebookAutopost\Models\FacebookShare;

final class Shared
{
    public function __construct(
        public readonly Model $shareable,
        public readonly FacebookShare $post,
    ) {}
}
