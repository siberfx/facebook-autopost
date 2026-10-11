<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;
use Siberfx\FacebookAutopost\Tests\Fixtures\PostStatus;
use Siberfx\FacebookAutopost\Tests\HeadlessTestCase;

uses(HeadlessTestCase::class);

beforeEach(function () {
    $this->user = new GenericUser(['id' => 1]);
    app(TokenStore::class)->put(new StoredConnection('member-token', '1234567', userAccessToken: 'user-token'));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('registers no views, Blade or Livewire components', function () {
    expect(View::getFinder()->getHints())->not->toHaveKey('facebook-autopost')
        ->and(app('blade.compiler')->getClassComponentNamespaces())->not->toHaveKey('facebook-autopost');

    if (class_exists(Livewire\Livewire::class)) {
        expect(fn () => app('livewire.factory')->resolveComponentClass('facebook-autopost.connection'))->toThrow(Exception::class);
    }
});

it('keeps the routes, the facade and the commands', function () {
    expect(app('router')->has('facebook-autopost.redirect'))->toBeTrue()
        ->and(app('router')->has('facebook-autopost.share'))->toBeTrue();

    $this->artisan('facebook:status')->assertSuccessful();
});

it('answers a form share with JSON instead of a redirect', function () {
    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['id' => '1234567_4'], 200)]);
    $url = URL::signedRoute('facebook-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertCreated()
        ->assertJsonPath('message', 'Shared on Facebook.')
        ->assertJsonPath('data.post_id', '1234567_4');
});

it('answers a form disconnect with JSON', function () {
    Http::fake(['https://graph.facebook.com/v25.0/me/permissions' => Http::response('', 200)]);

    $this->actingAs($this->user)->from('/admin/settings')->delete('/facebook/connection')
        ->assertOk()
        ->assertJsonPath('revoked', true)
        ->assertJsonPath('data.connected', false);
});

it('installs without asking for a theme', function () {
    $envDir = sys_get_temp_dir().'/facebook-autopost-'.uniqid();
    File::ensureDirectoryExists($envDir);
    File::put($envDir.'/.env', '');
    app()->useEnvironmentPath($envDir);

    try {
        $this->artisan('facebook:install')
            ->expectsOutputToContain('FACEBOOK_UI=false')
            ->expectsOutputToContain("route('facebook-autopost.redirect')")
            ->doesntExpectOutputToContain('Tailwind')
            ->assertSuccessful();

        expect(File::get($envDir.'/.env'))->toBe("FACEBOOK_UI=false\n");
    } finally {
        File::deleteDirectory($envDir);
        File::delete(config_path('facebook-autopost.php'));
        foreach (File::glob(database_path('migrations/*facebook_*_table.php')) as $migration) {
            File::delete($migration);
        }
    }
});
