<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Siberfx\FacebookAutopost\Facades\Facebook;
use Siberfx\FacebookAutopost\Tests\Fixtures\ArrayTokenStore;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;
use Siberfx\FacebookAutopost\Tests\Fixtures\PostStatus;

beforeEach(function () {
    ArrayTokenStore::$connection = null;
    config(['facebook-autopost.token_store' => ArrayTokenStore::class]);
    app()->forgetScopedInstances();
});

it('connects, shares and disconnects through a custom store', function () {
    Http::fake([
        'https://graph.facebook.com/v25.0/oauth/access_token?*' => Http::response(['access_token' => 'tok']),
        'https://graph.facebook.com/v25.0/1234567?*' => Http::response(['name' => 'Ada']),
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['data' => [['id' => '1234567', 'name' => 'Ada Page', 'access_token' => 'page-tok']]]),
        'https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['id' => '1234567_1'], 200),
        'https://graph.facebook.com/v25.0/me/permissions' => Http::response('', 200),
    ]);

    Facebook::completeConnection('code');
    expect(ArrayTokenStore::$connection?->accessToken)->toBe('page-tok')
        ->and(DB::table('facebook_connections')->count())->toBe(0);

    $post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
    expect(Facebook::share($post)->post_id)->toBe('1234567_1');

    Facebook::disconnect();
    expect(ArrayTokenStore::$connection)->toBeNull();
});
