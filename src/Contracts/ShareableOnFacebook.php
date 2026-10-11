<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Contracts;

use Siberfx\FacebookAutopost\Posts\FacebookPost;

/** Implemented by Eloquent models that can be shared on Facebook. */
interface ShareableOnFacebook
{
    /** Whether the model is public right now (e.g. published and not expired). */
    public function isLiveForFacebook(): bool;

    /** @throws \InvalidArgumentException When FacebookPost::make() is given an invalid URL. */
    public function toFacebookPost(): FacebookPost;
}
