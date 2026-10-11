<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Data;

use Carbon\CarbonImmutable;

/**
 * The connection including its tokens. Passed only between the token store
 * and the manager; never serialised to a response, log or event.
 *
 * $accessToken is the Page token used to post. $userAccessToken is the
 * long-lived user token, kept only so disconnect() can revoke the app.
 */
final readonly class StoredConnection
{
    public function __construct(
        public string $accessToken,
        public string $pageId,
        public ?string $name = null,
        public ?string $picture = null,
        public ?string $userAccessToken = null,
        public ?CarbonImmutable $expiresAt = null,
        public ?CarbonImmutable $connectedAt = null,
    ) {}
}
