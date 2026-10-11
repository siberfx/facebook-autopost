<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Data;

use Carbon\CarbonImmutable;

/** The connection as shown to people and APIs. Never carries a token. */
final readonly class Connection
{
    public function __construct(
        public bool $connected,
        public ?string $status = null,
        public ?string $name = null,
        public ?string $picture = null,
        public ?string $pageId = null,
        public ?CarbonImmutable $connectedAt = null,
        public ?CarbonImmutable $expiresAt = null,
    ) {}

    public static function disconnected(): self
    {
        return new self(connected: false);
    }

    public static function fromStored(StoredConnection $stored, string $status): self
    {
        return new self(
            connected: true,
            status: $status,
            name: $stored->name,
            picture: $stored->picture,
            pageId: $stored->pageId,
            connectedAt: $stored->connectedAt,
            expiresAt: $stored->expiresAt,
        );
    }

    public function withStatus(string $status): self
    {
        return new self($this->connected, $status, $this->name, $this->picture,
            $this->pageId, $this->connectedAt, $this->expiresAt);
    }

    /** Whole days until the token expires (negative once expired), or null when it never expires or is unknown. */
    public function daysLeft(): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return (int) floor((float) CarbonImmutable::now()->diffInSeconds($this->expiresAt, false) / 86400);
    }

    /**
     * Plain data for the cache. Objects are never cached: Laravel 13 stores
     * refuse to unserialize classes by default (cache.serializable_classes).
     *
     * @return array{connected: bool, status: ?string, name: ?string, picture: ?string, page_id: ?string, connected_at: ?string, expires_at: ?string}
     */
    public function toCache(): array
    {
        return [
            'connected' => $this->connected,
            'status' => $this->status,
            'name' => $this->name,
            'picture' => $this->picture,
            'page_id' => $this->pageId,
            'connected_at' => $this->connectedAt?->toIso8601String(),
            'expires_at' => $this->expiresAt?->toIso8601String(),
        ];
    }

    /** @param  array<mixed>  $data  What toCache() returned. */
    public static function fromCache(array $data): self
    {
        $string = fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $date = fn (string $key): ?CarbonImmutable => ($value = $string($key)) !== null ? CarbonImmutable::parse($value) : null;

        return new self(
            connected: ($data['connected'] ?? false) === true,
            status: $string('status'),
            name: $string('name'),
            picture: $string('picture'),
            pageId: $string('page_id'),
            connectedAt: $date('connected_at'),
            expiresAt: $date('expires_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->toCache() + ['days_left' => $this->daysLeft()];
    }
}
