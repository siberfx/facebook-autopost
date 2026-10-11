<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\Connection;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Events\Connected;
use Siberfx\FacebookAutopost\Events\Disconnected;
use Siberfx\FacebookAutopost\Events\Shared;
use Siberfx\FacebookAutopost\Exceptions\FacebookRequestFailed;
use Siberfx\FacebookAutopost\Exceptions\NotConnected;
use Siberfx\FacebookAutopost\Exceptions\NotShareable;
use Siberfx\FacebookAutopost\Facades\Facebook;
use Siberfx\FacebookAutopost\FacebookManager;
use Siberfx\FacebookAutopost\Models\FacebookShare;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;
use Siberfx\FacebookAutopost\Tests\Fixtures\PostStatus;

const DEBUG_URL = 'https://graph.facebook.com/v25.0/debug_token';
const PAGE_URL = 'https://graph.facebook.com/v25.0/1234567';
const FEED_URL = 'https://graph.facebook.com/v25.0/1234567/feed';
const PERMISSIONS_URL = 'https://graph.facebook.com/v25.0/me/permissions';

function connectForTest(string $token = 'page-token', ?string $userToken = 'user-token'): void
{
    app(TokenStore::class)->put(new StoredConnection(
        accessToken: $token,
        pageId: '1234567',
        name: 'Stored Name',
        userAccessToken: $userToken,
        connectedAt: CarbonImmutable::now()->subDays(10),
    ));
}

/** The manager remembers the stored connection per request; a token written behind its back needs a new one. */
function freshRequest(): void
{
    app()->forgetScopedInstances();
    Facebook::clearResolvedInstance(FacebookManager::class);
}

function publishedPost(): Post
{
    return Post::query()->create(['title' => 'Hello', 'status' => PostStatus::Published]);
}

function activePageFakes(): void
{
    Http::fake([
        DEBUG_URL.'?*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => 0]]),
        PAGE_URL.'?*' => Http::response(['name' => 'Siberfx Page', 'picture' => ['data' => ['url' => 'https://pic/p.jpg']]]),
    ]);
}

it('reads the stored connection once per request', function () {
    connectForTest();
    $facebook = app(FacebookManager::class);
    DB::enableQueryLog();

    foreach (range(1, 5) as $ignored) {
        expect($facebook->isConnected())->toBeTrue();
    }

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('forgets the remembered connection when it connects or disconnects', function () {
    connectForTest('old-page-token');
    expect(Facebook::isConnected())->toBeTrue();

    connectForTest('new-page-token');
    freshRequest();

    expect(Facebook::isConnected())->toBeTrue();
    expect(app(TokenStore::class)->get()?->accessToken)->toBe('new-page-token');
});

it('reports not connected without calling Facebook', function () {
    Http::fake();

    $connection = Facebook::connection();

    expect($connection->toArray())->toMatchArray([
        'connected' => false, 'status' => null, 'name' => null, 'picture' => null,
        'page_id' => null, 'connected_at' => null, 'expires_at' => null, 'days_left' => null,
    ]);
    Http::assertNothingSent();
});

it('describes an active Page connection from debug_token and the Page profile', function () {
    connectForTest();
    activePageFakes();

    $connection = Facebook::connection();

    expect($connection->connected)->toBeTrue()
        ->and($connection->status)->toBe('active')
        ->and($connection->name)->toBe('Siberfx Page')
        ->and($connection->picture)->toBe('https://pic/p.jpg')
        ->and($connection->pageId)->toBe('1234567')
        ->and($connection->expiresAt)->toBeNull();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), DEBUG_URL)
        && $request['access_token'] === 'app-id|app-secret'
        && $request['input_token'] === 'page-token');
});

it('reports an expiry from debug_token when Facebook sets one', function () {
    connectForTest();
    Http::fake([
        DEBUG_URL.'?*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => time() + 86400 * 30]]),
        PAGE_URL.'?*' => Http::response(['name' => 'Siberfx Page']),
    ]);

    expect(Facebook::connection()->daysLeft())->toBeGreaterThanOrEqual(29);
});

it('says unknown with the stored name when debug_token cannot be read', function () {
    connectForTest();
    Http::fake([DEBUG_URL.'?*' => Http::response(['error' => ['message' => 'down']], 500)]);

    $connection = Facebook::connection();

    expect($connection->status)->toBe('unknown')
        ->and($connection->name)->toBe('Stored Name');
});

it('reports an expired token without asking for the profile', function () {
    connectForTest();
    Http::fake([DEBUG_URL.'?*' => Http::response(['data' => ['is_valid' => false]])]);

    expect(Facebook::connection()->status)->toBe('expired');
    Http::assertNotSent(fn ($request) => str_starts_with($request->url(), PAGE_URL));
});

it('caches the status per token', function () {
    connectForTest();
    activePageFakes();

    Facebook::connection();
    Facebook::connection();

    Http::assertSentCount(2);
});

it('caches the status as plain data, so it survives a store that refuses objects', function () {
    connectForTest();
    activePageFakes();

    Facebook::connection();

    $cached = Cache::get('facebook-autopost:status:'.hash('sha256', 'page-token'));
    expect($cached)->toBeArray()
        ->and($cached['status'])->toBe('active')
        ->and($cached['page_id'])->toBe('1234567');
});

it('treats a cached value that is not plain data as a miss', function () {
    connectForTest();
    activePageFakes();
    Cache::put('facebook-autopost:status:'.hash('sha256', 'page-token'), 'garbage', 60);

    expect(Facebook::connection()->status)->toBe('active');
});

it('round-trips a connection through its cache form', function () {
    $connection = new Connection(true, 'active', 'Ada', 'https://pic', '1234567', CarbonImmutable::parse('2026-10-02 10:00:00'));

    $back = Connection::fromCache($connection->toCache());

    expect($back->name)->toBe('Ada')
        ->and($back->pageId)->toBe('1234567')
        ->and($back->connectedAt?->toIso8601String())->toBe($connection->connectedAt?->toIso8601String());
});

it('marks the cached status expired when Facebook refuses the token', function () {
    connectForTest();
    activePageFakes();
    Facebook::connection();

    Http::fake([FEED_URL => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400)]);

    expect(fn () => Facebook::share(publishedPost()))->toThrow(FacebookRequestFailed::class);
    expect(Facebook::connection()->status)->toBe('expired');
});

it('shares a live model to the Page feed, records the post and fires Shared', function () {
    Event::fake([Shared::class]);
    connectForTest();
    Http::fake([FEED_URL => Http::response(['id' => '1234567_99'])]);
    $post = publishedPost();

    $record = Facebook::share($post);

    expect($record->post_id)->toBe('1234567_99')
        ->and($record->status)->toBe(FacebookShare::STATUS_POSTED)
        ->and($record->trigger)->toBe(FacebookShare::TRIGGER_MANUAL)
        ->and($record->posted_at)->not->toBeNull()
        ->and(Facebook::wasPosted($post))->toBeTrue()
        ->and($post->wasPostedToFacebook())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === FEED_URL
        && $request['link'] === 'https://example.com/posts/'.$post->getKey()
        && $request['message'] === 'Hello'
        && $request->hasHeader('Authorization', 'Bearer page-token'));

    Event::assertDispatched(Shared::class, fn (Shared $e) => $e->post->is($record));
});

it('records a post Facebook accepted without returning its id', function () {
    connectForTest();
    Http::fake([FEED_URL => Http::response([], 200)]);

    $record = Facebook::share(publishedPost());

    expect($record->post_id)->toBeNull()
        ->and($record->status)->toBe(FacebookShare::STATUS_POSTED);
});

it('allows sharing the same model again', function () {
    connectForTest();
    Http::fake([FEED_URL => Http::sequence()
        ->push(['id' => '1234567_1'])
        ->push(['id' => '1234567_2'])]);
    $post = publishedPost();

    Facebook::share($post);
    Facebook::share($post);

    expect(FacebookShare::query()->for($post)->posted()->pluck('post_id')->all())->toBe(['1234567_1', '1234567_2']);
});

it('refuses to share when not connected or not live', function () {
    $draft = Post::query()->create(['title' => 'Draft', 'status' => PostStatus::Draft]);

    expect(fn () => Facebook::share(publishedPost()))->toThrow(NotConnected::class);

    connectForTest();
    freshRequest();
    expect(fn () => Facebook::share($draft))->toThrow(NotShareable::class);
});

it('completes a connection from an authorization code and stores the Page token without expiry', function () {
    Event::fake([Connected::class]);
    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::sequence()
            ->push(['access_token' => 'short-user'])
            ->push(['access_token' => 'long-user']),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [
            ['id' => '1234567', 'name' => 'Siberfx Page', 'access_token' => 'page-token'],
        ]]),
    ]);

    $connection = Facebook::completeConnection('auth-code');

    expect($connection->status)->toBe('active')
        ->and($connection->pageId)->toBe('1234567')
        ->and($connection->name)->toBe('Siberfx Page')
        ->and($connection->expiresAt)->toBeNull();

    $stored = app(TokenStore::class)->get();
    expect($stored?->accessToken)->toBe('page-token')
        ->and($stored?->userAccessToken)->toBe('long-user');

    Event::assertDispatched(Connected::class, fn (Connected $e) => $e->connection->pageId === '1234567');
});

it('picks the configured Page when several are managed', function () {
    config(['facebook-autopost.page_id' => '555']);
    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::sequence()
            ->push(['access_token' => 'short-user'])
            ->push(['access_token' => 'long-user']),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [
            ['id' => '111', 'name' => 'Other', 'access_token' => 'other-token'],
            ['id' => '555', 'name' => 'Wanted', 'access_token' => 'wanted-token'],
        ]]),
    ]);

    expect(Facebook::completeConnection('auth-code')->pageId)->toBe('555')
        ->and(app(TokenStore::class)->get()?->accessToken)->toBe('wanted-token');
});

it('refuses a connection when the account does not manage the configured Page', function () {
    config(['facebook-autopost.page_id' => '555']);
    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::sequence()
            ->push(['access_token' => 'short-user'])
            ->push(['access_token' => 'long-user']),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => []]),
    ]);

    expect(fn () => Facebook::completeConnection('auth-code'))
        ->toThrow(FacebookRequestFailed::class, 'does not manage the Page 555');
    expect(app(TokenStore::class)->get())->toBeNull();
});

it('disconnects, revoking the app at Facebook with the stored user token', function (?string $userToken, bool $revokeStatus, bool $expected) {
    Event::fake([Disconnected::class]);
    connectForTest(userToken: $userToken);
    Http::fake([PERMISSIONS_URL => Http::response(['success' => $revokeStatus], $revokeStatus ? 200 : 400)]);

    expect(Facebook::disconnect())->toBe($expected)
        ->and(app(TokenStore::class)->get())->toBeNull();

    if ($userToken !== null) {
        Http::assertSent(fn ($request) => $request->url() === PERMISSIONS_URL && $request->hasHeader('Authorization', 'Bearer user-token'));
    } else {
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), PERMISSIONS_URL));
    }

    Event::assertDispatched(Disconnected::class, fn (Disconnected $e) => $e->revoked === $expected);
})->with([
    'revoked' => ['user-token', true, true],
    'refused' => ['user-token', false, false],
    'no user token stored' => [null, true, false],
]);
