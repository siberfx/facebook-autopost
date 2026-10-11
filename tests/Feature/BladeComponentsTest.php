<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Models\FacebookShare;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;
use Siberfx\FacebookAutopost\Tests\Fixtures\PostStatus;

function uiConnect(int $daysLeft = 50, string $name = 'Ada Lovelace', bool $offline = true): void
{
    app(TokenStore::class)->put(new StoredConnection(
        'member-token', '1234567', name: $name,
        expiresAt: CarbonImmutable::now()->addDays($daysLeft)->addHour(),
        connectedAt: CarbonImmutable::parse('2026-10-02'),
    ));
    if ($offline) {
        Http::fake(['*' => Http::response('down', 503)]); // status "unknown" → stored profile, no network
    }
}

dataset('themes', ['tailwind', 'bootstrap']);

it('shows Connect when not connected', function (string $theme) {
    config(['facebook-autopost.ui.theme' => $theme]);

    $this->blade('<x-facebook-autopost::connection />')
        ->assertSee('Not connected')
        ->assertSee(route('facebook-autopost.redirect'), false)
        ->assertDontSee('Disconnect');
})->with('themes');

it('shows the connected member with Reconnect and Disconnect', function (string $theme) {
    config(['facebook-autopost.ui.theme' => $theme]);
    uiConnect();

    $this->blade('<x-facebook-autopost::connection />')
        ->assertSee('Ada Lovelace')
        ->assertSee('Page ID 1234567')
        ->assertSee('Status unknown')
        ->assertSee('Reconnect')
        ->assertSee('Disconnect')
        ->assertSee(route('facebook-autopost.connection.destroy'), false)
        ->assertSee('name="_method" value="DELETE"', false)
        ->assertDontSee('member-token');
})->with('themes');

it('warns when the token expires soon', function () {
    uiConnect(daysLeft: 5, offline: false); // the first matching Http::fake stub wins, so do not register the 503 one
    Http::fake([
        'https://graph.facebook.com/v25.0/debug_token?*' => Http::response(['data' => ['is_valid' => true, 'expires_at' => time() + 5 * 86400 + 3600]]),
        'https://graph.facebook.com/v25.0/1234567?*' => Http::response(['name' => 'Ada Lovelace']),
    ]);

    $this->blade('<x-facebook-autopost::connection />')->assertSee('Expires in 5 days');
});

it('shows the callback result, escaped', function (string $theme) {
    config(['facebook-autopost.ui.theme' => $theme]);
    request()->query->replace(['facebook' => 'error', 'reason' => '<script>alert(1)</script>']);

    $this->blade('<x-facebook-autopost::connection />')
        ->assertSee('Facebook refused the connection:')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false);
})->with('themes');

it('renders an enabled share button with a signed action', function (string $theme) {
    config(['facebook-autopost.ui.theme' => $theme]);
    uiConnect();
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    $this->blade('<x-facebook-autopost::share-button :model="$post" />', ['post' => $post])
        ->assertSee('Share on Facebook')
        ->assertSee('signature=', false)
        ->assertDontSee('disabled title=', false);
})->with('themes');

it('disables the share button with a reason', function (string $theme, Closure $arrange, string $reason) {
    config(['facebook-autopost.ui.theme' => $theme]);
    $post = $arrange();

    $this->blade('<x-facebook-autopost::share-button :model="$post" />', ['post' => $post])
        ->assertSee($reason)
        ->assertSee('disabled title=', false);
})->with('themes')->with([
    'not connected' => [fn () => Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published])), 'Connect Facebook first.'],
    'draft' => [function () {
        uiConnect();

        return Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Draft]);
    }, 'Only live items can be shared.'],
]);

it('offers a custom label with the last shared date', function () {
    uiConnect();
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
    FacebookShare::query()->create(['shareable_type' => 'post', 'shareable_id' => $post->id, 'post_id' => '1234567_1',
        'status' => FacebookShare::STATUS_POSTED, 'trigger' => FacebookShare::TRIGGER_MANUAL, 'posted_at' => CarbonImmutable::parse('2026-09-30')]);

    $this->blade('<x-facebook-autopost::share-button :model="$post" label="Post it" />', ['post' => $post])
        ->assertSee('Post it')
        ->assertSee('30 Sep 2026');

    $this->blade('<x-facebook-autopost::share-button :model="$post" />', ['post' => $post])->assertSee('Share again');
});

it('renders the session flash', function (string $theme) {
    config(['facebook-autopost.ui.theme' => $theme]);
    session()->flash('facebook-autopost.flash', ['type' => 'success', 'message' => 'Shared on Facebook.']);

    $this->blade('<x-facebook-autopost::flash />')->assertSee('Shared on Facebook.');
})->with('themes');
