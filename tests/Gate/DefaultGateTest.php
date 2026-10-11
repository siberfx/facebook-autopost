<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Livewire\ConnectionCard;
use Siberfx\FacebookAutopost\Livewire\ShareButton;
use Siberfx\FacebookAutopost\Models\FacebookShare;
use Siberfx\FacebookAutopost\Tests\DefaultGateTestCase;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;
use Siberfx\FacebookAutopost\Tests\Fixtures\PostStatus;

uses(DefaultGateTestCase::class);

beforeEach(function () {
    Http::fake();
    $this->user = new GenericUser(['id' => 1, 'name' => 'Admin']);
    app(TokenStore::class)->put(new StoredConnection('member-token', '1234567', userAccessToken: 'user-token', name: 'Ada Lovelace',
        expiresAt: CarbonImmutable::now()->addDays(50), connectedAt: CarbonImmutable::now()));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('defines the gate and denies everyone by default', function () {
    expect(Gate::has('manage-facebook-autopost'))->toBeTrue()
        ->and(Gate::forUser($this->user)->allows('manage-facebook-autopost'))->toBeFalse()
        ->and(Gate::allows('manage-facebook-autopost'))->toBeFalse();
});

it('refuses every endpoint to a logged-in user the gate does not allow', function () {
    $this->actingAs($this->user)->getJson('/facebook/redirect')->assertForbidden();
    $this->actingAs($this->user)->getJson('/facebook/callback?code=c&state=s')->assertForbidden();
    $this->actingAs($this->user)->getJson('/facebook/connection')->assertForbidden();
    $this->actingAs($this->user)->deleteJson('/facebook/connection')->assertForbidden();
    $this->actingAs($this->user)->postJson("/facebook/share/post/{$this->post->id}")->assertForbidden();
    $this->actingAs($this->user)
        ->postJson(URL::signedRoute('facebook-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]))
        ->assertForbidden();

    expect(app(TokenStore::class)->get())->not->toBeNull()
        ->and(FacebookShare::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses the Livewire actions', function () {
    $this->actingAs($this->user);

    Livewire::test(ConnectionCard::class)->call('disconnect')->assertForbidden();
    Livewire::test(ShareButton::class, ['model' => $this->post])->call('share')->assertForbidden();

    expect(app(TokenStore::class)->get())->not->toBeNull()
        ->and(FacebookShare::query()->count())->toBe(0);
    // Rendering the card may ask Facebook for the status; nothing is revoked or posted.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'revoke') || str_contains($request->url(), 'rest/posts'));
});

it('renders the share button disabled with the reason', function (string $theme) {
    config(['facebook-autopost.ui.theme' => $theme]);

    $this->actingAs($this->user)->blade('<x-facebook-autopost::share-button :model="$post" />', ['post' => $this->post])
        ->assertSee('You are not allowed to manage Facebook.')
        ->assertSeeHtml('disabled');
})->with(['tailwind', 'bootstrap']);

it('shows the connection read-only', function (string $theme, bool $livewire) {
    config(['facebook-autopost.ui.theme' => $theme]);
    Http::fake(['*' => Http::response('down', 503)]);
    $this->actingAs($this->user);

    $view = $livewire
        ? Livewire::test(ConnectionCard::class)
        : $this->blade('<x-facebook-autopost::connection />');

    $view->assertSee('Ada Lovelace')
        ->assertDontSee('Reconnect')
        ->assertDontSee('Disconnect')
        ->assertDontSee('Connect Facebook');
})->with(['tailwind', 'bootstrap'])->with(['blade' => false, 'livewire' => true]);

it('hides the connect button when not connected', function () {
    app(TokenStore::class)->forget();

    $this->actingAs($this->user)->blade('<x-facebook-autopost::connection />')
        ->assertSee('Not connected')
        ->assertDontSee('Connect Facebook');
});
