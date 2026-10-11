<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Events\ShareFailed;
use Siberfx\FacebookAutopost\Exceptions\FacebookRequestFailed;
use Siberfx\FacebookAutopost\Facades\Facebook;
use Siberfx\FacebookAutopost\FacebookManager;
use Siberfx\FacebookAutopost\Jobs\ShareOnFacebook;
use Siberfx\FacebookAutopost\Models\FacebookShare;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;
use Siberfx\FacebookAutopost\Tests\Fixtures\PostStatus;
use Siberfx\FacebookAutopost\Tests\Fixtures\RelativeUrlPost;

beforeEach(function () {
    Event::fake([ShareFailed::class]);
    app(TokenStore::class)->put(new StoredConnection('member-token', '1234567', userAccessToken: 'user-token', connectedAt: CarbonImmutable::now()));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Hi', 'status' => PostStatus::Published]));
});

it('fails at once, without retrying, when Facebook refuses the request', function (int $status) {
    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['error' => ['message' => 'No']], $status)]);

    dispatch(new ShareOnFacebook('post', $this->post->id)); // sync queue: the job is marked failed, nothing is thrown

    $row = FacebookShare::query()->for($this->post)->sole();
    expect($row->status)->toBe(FacebookShare::STATUS_FAILED)
        ->and($row->error)->toBe('Facebook rejected the request: No');
    Http::assertSentCount(1);
    Event::assertDispatchedTimes(ShareFailed::class, 1);
})->with(['unauthorized' => 401, 'forbidden' => 403, 'unprocessable' => 422]);

it('fails at once when the model builds an invalid post', function () {
    Http::fake();
    $post = RelativeUrlPost::query()->create(['title' => 'Bad', 'status' => 'published']);

    dispatch(new ShareOnFacebook(RelativeUrlPost::class, $post->id));

    expect(FacebookShare::query()->where('status', FacebookShare::STATUS_FAILED)->count())->toBe(1);
    Http::assertNothingSent();
    Event::assertDispatchedTimes(ShareFailed::class, 1);
});

it('rethrows failures that may succeed later, so the queue retries them', function (int $status) {
    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['error' => ['message' => 'Busy']], $status)]);

    expect(fn () => (new ShareOnFacebook('post', $this->post->id))->handle(app(FacebookManager::class)))
        ->toThrow(FacebookRequestFailed::class, 'Facebook rejected the request: Busy');
    expect(FacebookShare::query()->count())->toBe(0);
    Event::assertNotDispatched(ShareFailed::class);
})->with(['rate limited' => 429, 'server error' => 500, 'unavailable' => 503]);

it('rethrows a network failure for retry', function () {
    Http::fake(['https://graph.facebook.com/v25.0/1234567/feed' => fn () => throw new ConnectionException('timed out')]);

    expect(fn () => (new ShareOnFacebook('post', $this->post->id))->handle(app(FacebookManager::class)))
        ->toThrow(FacebookRequestFailed::class, 'Could not reach Facebook: timed out');
    Event::assertNotDispatched(ShareFailed::class);
});

it('is unique per shareable while queued', function () {
    $job = new ShareOnFacebook('post', 42);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('post:42')
        ->and($job->uniqueFor)->toBe(600);
});

it('does not queue a second share for the same model while one is waiting', function () {
    Queue::fake();
    $other = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Other', 'status' => PostStatus::Published]));

    Facebook::queue($this->post);
    Facebook::queue($this->post);
    Facebook::queue($other);

    Queue::assertPushed(ShareOnFacebook::class, 2);
});
