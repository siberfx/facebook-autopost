<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\Connection;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Events\Connected;
use Siberfx\FacebookAutopost\Events\Disconnected;
use Siberfx\FacebookAutopost\Events\Shared;
use Siberfx\FacebookAutopost\Exceptions\FacebookRequestFailed;
use Siberfx\FacebookAutopost\Exceptions\NotConnected;
use Siberfx\FacebookAutopost\Exceptions\NotShareable;
use Siberfx\FacebookAutopost\Graph\GraphClient;
use Siberfx\FacebookAutopost\Jobs\ShareOnFacebook;
use Siberfx\FacebookAutopost\Models\FacebookShare;

final class FacebookManager
{
    public function __construct(
        private readonly TokenStore $store,
        private readonly GraphClient $graph,
    ) {}

    /** The manager is scoped per request/job, so this is read (query + decrypt) at most once each. */
    private ?StoredConnection $stored = null;

    private bool $storedLoaded = false;

    public function isConnected(): bool
    {
        return $this->stored() !== null;
    }

    public function connection(): Connection
    {
        $stored = $this->stored();

        if ($stored === null) {
            return Connection::disconnected();
        }

        $cached = Cache::get($this->cacheKey($stored));

        if (is_array($cached)) {
            return Connection::fromCache($cached);
        }

        $connection = $this->fetchStatus($stored);
        $this->remember($stored, $connection);

        return $connection;
    }

    /**
     * Post the model to the Page now. Re-sharing an already posted model is allowed.
     *
     * @throws NotConnected|NotShareable|FacebookRequestFailed
     * @throws InvalidArgumentException When the model's toFacebookPost() builds an invalid post.
     */
    public function share(Model&ShareableOnFacebook $model, string $trigger = FacebookShare::TRIGGER_MANUAL): FacebookShare
    {
        $stored = $this->stored() ?? throw new NotConnected;

        if (! $model->isLiveForFacebook()) {
            throw new NotShareable;
        }

        try {
            $id = $this->graph->postLink($stored->accessToken, $stored->pageId, $model->toFacebookPost());
        } catch (FacebookRequestFailed $e) {
            if ($e->isUnauthorized()) {
                $this->remember($stored, $this->connection()->withStatus('expired'));
            }

            throw $e;
        }

        $record = FacebookShare::query()->create([
            'shareable_type' => $model->getMorphClass(),
            'shareable_id' => $model->getKey(),
            'post_id' => $id,
            'status' => FacebookShare::STATUS_POSTED,
            'trigger' => $trigger,
            'posted_at' => CarbonImmutable::now(),
        ]);

        event(new Shared($model, $record));

        return $record;
    }

    public function authorizationUrl(string $state): string
    {
        return $this->graph->authorizationUrl($state);
    }

    public function queue(Model&ShareableOnFacebook $model): void
    {
        $job = new ShareOnFacebook($model->getMorphClass(), $model->getKey());

        dispatch($job
            ->onConnection(config('facebook-autopost.autopost.queue_connection'))
            ->onQueue(config('facebook-autopost.autopost.queue')));
    }

    public function wasPosted(Model $model): bool
    {
        return FacebookShare::query()->for($model)->posted()->exists();
    }

    /**
     * Forget the stored connection and, when a user token is stored, remove
     * the app's permissions at Facebook, which revokes the Page token too.
     *
     * @return bool Whether Facebook confirmed the revocation; the token is forgotten either way.
     */
    public function disconnect(): bool
    {
        $stored = $this->stored();

        if ($stored !== null) {
            Cache::forget($this->cacheKey($stored));
        }

        $revoked = match (true) {
            $stored === null => true,
            $stored->userAccessToken === null => false,
            default => $this->graph->revoke($stored->userAccessToken),
        };

        $this->store->forget();
        $this->forgetStored();
        event(new Disconnected($revoked));

        return $revoked;
    }

    /** @throws FacebookRequestFailed */
    public function completeConnection(string $code): Connection
    {
        $stored = $this->graph->exchange($code);
        $this->store->put($stored);
        $this->forgetStored();

        $connection = Connection::fromStored($stored, 'active');
        $this->remember($stored, $connection);
        event(new Connected($connection));

        return $connection;
    }

    private function stored(): ?StoredConnection
    {
        if (! $this->storedLoaded) {
            $this->stored = $this->store->get();
            $this->storedLoaded = true;
        }

        return $this->stored;
    }

    private function forgetStored(): void
    {
        $this->stored = null;
        $this->storedLoaded = false;
    }

    private function fetchStatus(StoredConnection $stored): Connection
    {
        $debug = $this->graph->debugToken($stored->accessToken);

        if ($debug === null) {
            return Connection::fromStored($stored, 'unknown');
        }

        if (! ($debug['is_valid'] ?? false)) {
            return Connection::fromStored($stored, 'expired');
        }

        $profile = $this->graph->pageProfile($stored->accessToken, $stored->pageId) ?? [];
        $expiresAt = is_numeric($debug['expires_at'] ?? null) ? (int) $debug['expires_at'] : 0;

        return new Connection(
            connected: true,
            status: 'active',
            name: self::string($profile['name'] ?? null) ?? $stored->name,
            picture: self::string($profile['picture']['data']['url'] ?? null) ?? $stored->picture,
            pageId: $stored->pageId,
            connectedAt: $stored->connectedAt,
            // 0 means the token never expires.
            expiresAt: $expiresAt > 0 ? CarbonImmutable::createFromTimestamp($expiresAt) : null,
        );
    }

    /** Only plain arrays go into the cache; see Connection::toCache(). */
    private function remember(StoredConnection $stored, Connection $connection): void
    {
        Cache::put($this->cacheKey($stored), $connection->toCache(), $this->cacheTtl());
    }

    private function cacheKey(StoredConnection $stored): string
    {
        return 'facebook-autopost:status:'.hash('sha256', $stored->accessToken);
    }

    private function cacheTtl(): int
    {
        return (int) config('facebook-autopost.status_cache_ttl', 300);
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
