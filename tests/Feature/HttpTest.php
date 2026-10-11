<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Models\FacebookShare;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;
use Siberfx\FacebookAutopost\Tests\Fixtures\PostStatus;
use Siberfx\FacebookAutopost\Tests\Fixtures\RelativeUrlPost;

beforeEach(function () {
    $this->user = new GenericUser(['id' => 1, 'name' => 'Admin']);
    config(['facebook-autopost.routes.after_connect' => '/admin/integrations']);
});

function stateFrom($response): string
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['state'];
}

it('requires the configured middleware', function () {
    // JSON requests: Testbench has no "login" route to redirect guests to.
    $this->getJson('/facebook/redirect')->assertUnauthorized();
    $this->getJson('/facebook/connection')->assertUnauthorized();
    $this->postJson('/facebook/share/post/1')->assertUnauthorized();
});

it('redirects to Facebook with a state stored in the session', function () {
    $response = $this->actingAs($this->user)->get('/facebook/redirect');

    $response->assertRedirectContains('https://www.facebook.com/v25.0/dialog/oauth');
    expect(stateFrom($response))->toHaveLength(40)->toBe(session('facebook-autopost.state'));
});

it('sends people back when credentials are missing', function () {
    config(['facebook-autopost.app_id' => null]);

    $this->actingAs($this->user)->get('/facebook/redirect')
        ->assertRedirect('/admin/integrations?facebook=not_configured');
});

it('connects on a valid callback', function () {
    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::response(['access_token' => 'tok']),
        'https://graph.facebook.com/v25.0/1234567?*' => Http::response(['name' => 'Ada']),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [['id' => '1234567', 'name' => 'Ada Page', 'access_token' => 'page-tok']]]),
    ]);
    $state = stateFrom($this->actingAs($this->user)->get('/facebook/redirect'));

    $this->actingAs($this->user)->get('/facebook/callback?'.http_build_query(['code' => 'c', 'state' => $state]))
        ->assertRedirect('/admin/integrations?facebook=connected');

    expect(app(TokenStore::class)->get()?->pageId)->toBe('1234567');
});

it('rejects a callback whose state does not match, and one replayed', function () {
    $state = stateFrom($this->actingAs($this->user)->get('/facebook/redirect'));

    $this->actingAs($this->user)->get('/facebook/callback?code=c&state=forged')
        ->assertRedirect('/admin/integrations?facebook=invalid_state');
    $this->actingAs($this->user)->get('/facebook/callback?'.http_build_query(['code' => 'c', 'state' => $state]))
        ->assertRedirect('/admin/integrations?facebook=invalid_state'); // state was pulled by the first callback
});

it('passes Facebook errors back', function (array $query, array $expected) {
    $state = stateFrom($this->actingAs($this->user)->get('/facebook/redirect'));

    $this->actingAs($this->user)->get('/facebook/callback?'.http_build_query($query + ['state' => $state]))
        ->assertRedirect('/admin/integrations?'.http_build_query($expected));
})->with([
    'scope refused' => [
        ['error' => 'unauthorized_scope_error', 'error_description' => 'Scope "profile" is not authorized for your application'],
        ['facebook' => 'error', 'reason' => 'Scope "profile" is not authorized for your application'],
    ],
    'cancelled' => [['error' => 'access_denied', 'error_reason' => 'user_denied'], ['facebook' => 'cancelled']],
]);

it('logs a failed exchange without the code or secret', function () {
    Log::spy();
    Http::fake(['https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::response(['error' => ['message' => 'authorization code not found', 'code' => 100]], 400)]);
    $state = stateFrom($this->actingAs($this->user)->get('/facebook/redirect'));

    $this->actingAs($this->user)->get('/facebook/callback?'.http_build_query(['code' => 'secret-code', 'state' => $state]))
        ->assertRedirect('/admin/integrations?facebook=exchange_failed');

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $message === 'Facebook token exchange failed.'
        && str_contains($context['error'], 'authorization code not found')
        && ! str_contains(json_encode($context), 'secret-code')
        && ! str_contains(json_encode($context), 'client-secret'));
});

it('shows and deletes the connection as JSON', function () {
    Http::fake([
        'https://graph.facebook.com/v25.0/debug_token?*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => 0]]),
        'https://graph.facebook.com/v25.0/1234567?*' => Http::response(['name' => 'Ada']),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [['id' => '1234567', 'name' => 'Ada Page', 'access_token' => 'page-tok']]]),
        'https://graph.facebook.com/v25.0/me/permissions' => Http::response('', 200),
    ]);
    app(TokenStore::class)->put(new StoredConnection('member-token', '1234567', userAccessToken: 'user-token', connectedAt: CarbonImmutable::now()));

    $this->actingAs($this->user)->getJson('/facebook/connection')
        ->assertOk()
        ->assertJsonPath('data.connected', true)
        ->assertJsonPath('data.name', 'Ada')
        ->assertJsonMissingPath('data.access_token');

    $this->actingAs($this->user)->deleteJson('/facebook/connection')
        ->assertOk()
        ->assertJsonPath('revoked', true)
        ->assertJsonPath('data.connected', false);
});

it('shares a model by morph alias', function () {
    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['id' => '1234567_3'], 200)]);
    app(TokenStore::class)->put(new StoredConnection('member-token', '1234567', userAccessToken: 'user-token'));
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]);

    $this->actingAs($this->user)->postJson("/facebook/share/post/{$post->id}")
        ->assertCreated()
        ->assertJsonPath('data.post_id', '1234567_3');
});

it('answers 422 when not connected or not live, and 502 when Facebook refuses', function () {
    $post = Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]);
    $draft = Post::query()->create(['title' => 'Draft', 'status' => PostStatus::Draft]);

    $this->actingAs($this->user)->postJson("/facebook/share/post/{$post->id}")
        ->assertStatus(422)->assertJsonPath('message', 'Facebook is not connected.');

    app(TokenStore::class)->put(new StoredConnection('member-token', '1234567', userAccessToken: 'user-token'));
    app()->forgetScopedInstances(); // the manager remembers the connection per request; start a new one
    $this->actingAs($this->user)->postJson("/facebook/share/post/{$draft->id}")->assertStatus(422);

    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);
    $this->actingAs($this->user)->postJson("/facebook/share/post/{$post->id}")
        ->assertStatus(502)->assertJsonPath('message', 'Facebook rejected the request: Rate limited');
});

it('answers 404 for anything that is not a known shareable record', function (string $type, string $id) {
    $this->actingAs($this->user)->postJson("/facebook/share/{$type}/{$id}")->assertNotFound();
})->with([
    'unknown alias' => ['nope', '1'],
    'class name instead of alias' => [urlencode(Post::class), '1'],
    'deleted record' => ['post', '999'],
]);

it('registers its routes by default', function () {
    expect(app('router')->has('facebook-autopost.callback'))->toBeTrue();
});

it('answers 422 when the model cannot build a valid Facebook post', function () {
    Http::fake();
    Relation::morphMap(['relative-url-post' => RelativeUrlPost::class]);
    app(TokenStore::class)->put(new StoredConnection('member-token', '1234567', userAccessToken: 'user-token'));
    $post = RelativeUrlPost::query()->create(['title' => 'Hi', 'status' => 'published']);

    $this->actingAs($this->user)->postJson("/facebook/share/relative-url-post/{$post->id}")
        ->assertStatus(422)
        ->assertJsonPath('message', 'Facebook posts need an absolute http(s) URL, got [/posts/'.$post->id.'].');
    $this->actingAs($this->user)
        ->postJson(URL::signedRoute('facebook-autopost.share.signed', ['type' => RelativeUrlPost::class, 'id' => $post->id]))
        ->assertStatus(422);

    Http::assertNothingSent();
    expect(FacebookShare::query()->count())->toBe(0);
});
