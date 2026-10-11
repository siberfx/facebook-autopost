<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Models\FacebookShare;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;
use Siberfx\FacebookAutopost\Tests\Fixtures\PostStatus;
use Siberfx\FacebookAutopost\Tests\Fixtures\RelativeUrlPost;

beforeEach(function () {
    $this->user = new GenericUser(['id' => 1]);
    app(TokenStore::class)->put(new StoredConnection('member-token', '1234567', userAccessToken: 'user-token'));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('disconnects from a form and redirects back with a message', function () {
    Http::fake(['https://graph.facebook.com/v25.0/me/permissions' => Http::response('', 200)]);

    $this->actingAs($this->user)->from('/admin/settings')->delete('/facebook/connection')
        ->assertRedirect('/admin/settings')
        ->assertSessionHas('facebook-autopost.flash', ['type' => 'success', 'message' => 'Facebook disconnected.']);
});

it('shares through the signed url without a morph map entry', function () {
    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['id' => '1234567_4'], 200)]);
    $url = URL::signedRoute('facebook-autopost.share.signed', ['type' => Post::class, 'id' => $this->post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertRedirect('/admin/posts')
        ->assertSessionHas('facebook-autopost.flash', ['type' => 'success', 'message' => 'Shared on Facebook.']);

    expect(FacebookShare::query()->for($this->post)->posted()->value('post_id'))->toBe('1234567_4');
});

it('rejects a signed url whose type or id was changed', function () {
    Http::fake();
    $url = URL::signedRoute('facebook-autopost.share.signed', ['type' => Post::class, 'id' => $this->post->id]);

    $this->actingAs($this->user)->post(str_replace('id='.$this->post->id, 'id=999', $url))->assertForbidden();
    $this->actingAs($this->user)->post(str_replace(urlencode(Post::class), urlencode(GenericUser::class), $url))->assertForbidden();
    Http::assertNothingSent();
});

it('flashes Facebook errors on a form share', function () {
    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['error' => ['message' => 'Rate limited']], 429)]);
    $url = URL::signedRoute('facebook-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertRedirect('/admin/posts')
        ->assertSessionHas('facebook-autopost.flash', ['type' => 'error', 'message' => 'Facebook rejected the request: Rate limited']);
});

it('still answers JSON to JSON requests on the signed route', function () {
    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['id' => '1234567_6'], 200)]);
    $url = URL::signedRoute('facebook-autopost.share.signed', ['type' => 'post', 'id' => $this->post->id]);

    $this->actingAs($this->user)->postJson($url)->assertCreated()->assertJsonPath('data.post_id', '1234567_6');
});

it('flashes an error when the model cannot build a valid Facebook post', function () {
    Http::fake();
    $post = RelativeUrlPost::query()->create(['title' => 'Hi', 'status' => 'published']);
    $url = URL::signedRoute('facebook-autopost.share.signed', ['type' => RelativeUrlPost::class, 'id' => $post->id]);

    $this->actingAs($this->user)->from('/admin/posts')->post($url)
        ->assertRedirect('/admin/posts')
        ->assertSessionHas('facebook-autopost.flash', ['type' => 'error', 'message' => 'Facebook posts need an absolute http(s) URL, got [/posts/'.$post->id.'].']);
    Http::assertNothingSent();
});
