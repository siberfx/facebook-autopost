<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Siberfx\FacebookAutopost\Exceptions\FacebookRequestFailed;
use Siberfx\FacebookAutopost\Exceptions\NotConfigured;
use Siberfx\FacebookAutopost\Graph\GraphClient;
use Siberfx\FacebookAutopost\Posts\FacebookPost;

const FEED = 'https://graph.facebook.com/v25.0/1234567/feed';

function authorizeQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

it('builds the Login dialog URL for the Graph API version and the Page scopes', function () {
    $url = app(GraphClient::class)->authorizationUrl('state-123');

    expect(str_starts_with($url, 'https://www.facebook.com/v25.0/dialog/oauth?'))->toBeTrue()
        ->and(authorizeQuery($url))->toMatchArray([
            'client_id' => 'app-id',
            'response_type' => 'code',
            'state' => 'state-123',
            'scope' => 'pages_show_list,pages_manage_posts,pages_read_engagement',
            'redirect_uri' => url('/facebook/callback'),
        ]);
});

it('uses the configured redirect URI when one is set', function () {
    config(['facebook-autopost.redirect_uri' => 'https://example.com/fb/cb']);

    expect(authorizeQuery(app(GraphClient::class)->authorizationUrl('s'))['redirect_uri'])
        ->toBe('https://example.com/fb/cb');
});

it('refuses to build a dialog URL without the app credentials', function () {
    config(['facebook-autopost.app_secret' => null]);

    expect(fn () => app(GraphClient::class)->authorizationUrl('s'))->toThrow(NotConfigured::class);
});

it('exchanges the code for a long-lived user token and takes the Page token', function () {
    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::sequence()
            ->push(['access_token' => 'short-user'])
            ->push(['access_token' => 'long-user', 'expires_in' => 5184000]),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [
            ['id' => '1234567', 'name' => 'Siberfx Page', 'access_token' => 'page-token'],
        ]]),
    ]);

    $stored = app(GraphClient::class)->exchange('auth-code');

    expect($stored->accessToken)->toBe('page-token')
        ->and($stored->pageId)->toBe('1234567')
        ->and($stored->userAccessToken)->toBe('long-user')
        ->and($stored->expiresAt)->toBeNull();

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.facebook.com/v25.0/oauth/access_token')
        && str_contains($request->url(), 'grant_type=fb_exchange_token')
        && str_contains($request->url(), 'fb_exchange_token=short-user'));
});

it('fails the exchange when Facebook returns no user token', function () {
    Http::fake(['https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::response(['error' => ['message' => 'Invalid code']], 400)]);

    expect(fn () => app(GraphClient::class)->exchange('bad'))
        ->toThrow(FacebookRequestFailed::class, 'Invalid code');
});

it('posts the commentary and the link to the Page feed and returns the post id', function () {
    Http::fake([FEED => Http::response(['id' => '1234567_42'])]);

    $id = app(GraphClient::class)->postLink('page-token', '1234567',
        FacebookPost::make('https://example.com/x', 'Title')->commentary('New: {title}'));

    expect($id)->toBe('1234567_42');
    Http::assertSent(fn (Request $request) => $request->url() === FEED
        && $request['message'] === 'New: Title'
        && $request['link'] === 'https://example.com/x'
        && $request->hasHeader('Authorization', 'Bearer page-token'));
});

it('returns null when Facebook accepts the post without an id', function () {
    Http::fake([FEED => Http::response([], 200)]);

    expect(app(GraphClient::class)->postLink('t', '1234567', FacebookPost::make('https://example.com/x', 'X')))->toBeNull();
});

it('reports a refused post with Facebook\'s message and whether the token is invalid', function () {
    Http::fake([FEED => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 400)]);

    try {
        app(GraphClient::class)->postLink('t', '1234567', FacebookPost::make('https://example.com/x', 'X'));
        $this->fail('Expected a refused post to throw.');
    } catch (FacebookRequestFailed $e) {
        expect($e->getMessage())->toBe('Facebook rejected the request: Invalid OAuth access token.')
            ->and($e->isUnauthorized())->toBeTrue()
            ->and($e->isPermanent())->toBeTrue();
    }
});

it('treats a rate limit as retryable', function () {
    Http::fake([FEED => Http::response(['error' => ['message' => 'Rate limited']], 429)]);

    try {
        app(GraphClient::class)->postLink('t', '1234567', FacebookPost::make('https://example.com/x', 'X'));
        $this->fail('Expected a rate limit to throw.');
    } catch (FacebookRequestFailed $e) {
        expect($e->isPermanent())->toBeFalse()
            ->and($e->isUnauthorized())->toBeFalse();
    }
});

it('wraps a network failure as a retryable request failure', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => app(GraphClient::class)->postLink('t', '1234567', FacebookPost::make('https://example.com/x', 'X')))
        ->toThrow(FacebookRequestFailed::class, 'Could not reach Facebook: timed out');
});

it('returns null from debug_token and the Page profile when Facebook cannot answer', function () {
    Http::fake([
        'https://graph.facebook.com/v25.0/debug_token?*' => Http::response('down', 500),
        'https://graph.facebook.com/v25.0/1234567?*' => Http::response('down', 500),
    ]);

    $client = app(GraphClient::class);

    expect($client->debugToken('t'))->toBeNull()
        ->and($client->pageProfile('t', '1234567'))->toBeNull();
});

it('revokes with the user token and reports false when the call fails', function () {
    Http::fake(['https://graph.facebook.com/v25.0/me/permissions' => Http::response(['success' => true])]);
    expect(app(GraphClient::class)->revoke('user-token'))->toBeTrue();

    Http::fake(fn () => throw new ConnectionException('offline'));
    expect(app(GraphClient::class)->revoke('user-token'))->toBeFalse();
});
