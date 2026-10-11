<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Livewire\ConnectionCard;
use Siberfx\FacebookAutopost\Livewire\ShareButton;
use Siberfx\FacebookAutopost\Models\FacebookShare;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;
use Siberfx\FacebookAutopost\Tests\Fixtures\PostStatus;
use Siberfx\FacebookAutopost\Tests\Fixtures\RelativeUrlPost;

beforeEach(function () {
    app(TokenStore::class)->put(new StoredConnection('member-token', '1234567', userAccessToken: 'user-token', name: 'Ada Lovelace',
        expiresAt: CarbonImmutable::now()->addDays(50), connectedAt: CarbonImmutable::now()));
});

it('registers the connection card by name', function () {
    Http::fake(['*' => Http::response('down', 503)]);

    $this->blade('<livewire:facebook-autopost.connection />')->assertSee('Ada Lovelace');
});

it('disconnects from the card', function (string $theme) {
    config(['facebook-autopost.ui.theme' => $theme]);
    Http::fake(['https://graph.facebook.com/v25.0/me/permissions' => Http::response('', 200), '*' => Http::response('down', 503)]);

    Livewire::test(ConnectionCard::class)
        ->assertSee('Ada Lovelace')
        ->assertSeeHtml('wire:click="disconnect"')
        ->call('disconnect')
        ->assertSee('Facebook disconnected.')
        ->assertSee('Not connected');

    expect(app(TokenStore::class)->get())->toBeNull();
})->with(['tailwind', 'bootstrap']);

it('shares from the button and shows the result', function (string $theme) {
    config(['facebook-autopost.ui.theme' => $theme]);
    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['id' => '1234567_11'], 200)]);
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    Livewire::test(ShareButton::class, ['model' => $post])
        ->assertSeeHtml('wire:click="share"')
        ->call('share')
        ->assertSee('Shared on Facebook.')
        ->assertSee('Share again')
        ->assertDispatched('facebook-autopost-shared', id: '1234567_11');

    expect(FacebookShare::query()->for($post)->posted()->count())->toBe(1);
})->with(['tailwind', 'bootstrap']);

it('shows Facebook errors on the button', function () {
    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    Livewire::test(ShareButton::class, ['model' => $post])
        ->call('share')
        ->assertSee('Facebook rejected the request: Rate limited');
});

it('refuses to change which record the button shares', function () {
    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));

    expect(fn () => Livewire::test(ShareButton::class, ['model' => $post])->set('shareableId', '999'))
        ->toThrow(Exception::class, 'locked');
    expect(fn () => Livewire::test(ShareButton::class, ['model' => $post])->set('shareableType', GenericUser::class))
        ->toThrow(Exception::class, 'locked');
});

it('shows an invalid post URL on the button instead of failing', function () {
    Http::fake();
    $post = RelativeUrlPost::query()->create(['title' => 'Hi', 'status' => 'published']);

    Livewire::test(ShareButton::class, ['model' => $post])
        ->call('share')
        ->assertOk()
        ->assertSee('Facebook posts need an absolute http(s) URL');
    Http::assertNothingSent();
});
