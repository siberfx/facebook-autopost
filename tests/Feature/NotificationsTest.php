<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\Connection;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Events\ShareFailed;
use Siberfx\FacebookAutopost\Events\TokenExpiringSoon;
use Siberfx\FacebookAutopost\Exceptions\NotificationFailed;
use Siberfx\FacebookAutopost\Facades\Facebook;
use Siberfx\FacebookAutopost\Jobs\SendNotification;
use Siberfx\FacebookAutopost\Notifications\Message;
use Siberfx\FacebookAutopost\Notifications\NotificationMail;
use Siberfx\FacebookAutopost\Notifications\Notifier;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;
use Siberfx\FacebookAutopost\Tests\Fixtures\PostStatus;
use Siberfx\FacebookAutopost\Tests\Fixtures\RecordingChannel;

const TELEGRAM = 'https://api.telegram.org/bot123:secret/sendMessage';
const SLACK = 'https://hooks.slack.com/services/T0/B0/xyz';

beforeEach(function () {
    app(TokenStore::class)->put(new StoredConnection('member-token', '1234567', userAccessToken: 'user-token', connectedAt: CarbonImmutable::now()));
    $this->post = Post::withoutEvents(fn () => Post::query()->create(['title' => 'Fish & <Chips>', 'status' => PostStatus::Published]));

    config([
        'facebook-autopost.notifications.channels.telegram.bot_token' => '123:secret',
        'facebook-autopost.notifications.channels.telegram.chat_id' => '-10042',
        'facebook-autopost.notifications.channels.slack.webhook_url' => SLACK,
    ]);
});

function enableNotifications(string ...$channels): void
{
    config(['facebook-autopost.notifications.enabled' => true]);

    foreach ($channels as $channel) {
        config(["facebook-autopost.notifications.channels.{$channel}.enabled" => true]);
    }
}

function fakeFacebookAndChannels(): void
{
    Http::fake([
        'https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['id' => '1234567_7'], 200),
        TELEGRAM => Http::response(['ok' => true, 'result' => []]),
        SLACK => Http::response('ok'),
    ]);
}

it('is off by default', function () {
    expect(config('facebook-autopost.notifications.enabled'))->toBeFalse()
        ->and(config('facebook-autopost.notifications.channels.telegram.enabled'))->toBeFalse()
        ->and(config('facebook-autopost.notifications.channels.slack.enabled'))->toBeFalse();

    fakeFacebookAndChannels();
    Facebook::share($this->post);

    Http::assertSentCount(1);
});

it('sends nothing while the master switch is off, even with channels enabled', function () {
    config(['facebook-autopost.notifications.channels.telegram.enabled' => true]);
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.telegram.org'));
});

it('tells Telegram about a post, escaped for HTML', function () {
    enableNotifications('telegram');
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Http::assertSent(function (Request $request) {
        if ($request->url() !== TELEGRAM) {
            return false;
        }

        expect($request['chat_id'])->toBe('-10042')
            ->and($request['parse_mode'])->toBe('HTML')
            ->and($request['text'])->toContain('<b>✅ Shared on Facebook: Fish &amp; &lt;Chips&gt;</b>')
            ->and($request['text'])->toContain('https://example.com/posts/'.$this->post->id)
            ->and($request['text'])->toContain('Post: https://www.facebook.com/1234567_7')
            ->and($request['text'])->toContain('Trigger: manual')
            ->and($request->data())->not->toHaveKey('message_thread_id');

        return true;
    });
});

it('posts to a Telegram forum topic when a thread id is set', function () {
    enableNotifications('telegram');
    config(['facebook-autopost.notifications.channels.telegram.thread_id' => '9']);
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Http::assertSent(fn (Request $request) => $request->url() === TELEGRAM && $request['message_thread_id'] === 9);
});

it('tells Slack about a post', function () {
    enableNotifications('slack');
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Http::assertSent(fn (Request $request) => $request->url() === SLACK
        && str_contains($request['text'], '*✅ Shared on Facebook: Fish &amp; &lt;Chips&gt;*')
        && str_contains($request['text'], '1234567_7'));
});

it('sends to both channels when both are on', function () {
    enableNotifications('telegram', 'slack');
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Http::assertSentCount(3);
});

it('can switch off the message for a single event', function () {
    enableNotifications('slack');
    config(['facebook-autopost.notifications.events.shared' => false]);
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Http::assertNotSent(fn (Request $request) => $request->url() === SLACK);
});

it('skips an enabled channel that lacks its settings', function () {
    enableNotifications('telegram', 'slack');
    config(['facebook-autopost.notifications.channels.telegram.chat_id' => null]);
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.telegram.org'));
    Http::assertSent(fn (Request $request) => $request->url() === SLACK);
});

it('never fails the share when a channel fails, and still sends to the others', function () {
    Exceptions::fake();
    enableNotifications('telegram', 'slack');
    Http::fake([
        'https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['id' => '1234567_7'], 200),
        TELEGRAM => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
        SLACK => Http::response('ok'),
    ]);

    $record = Facebook::share($this->post);

    expect($record->post_id)->toBe('1234567_7');
    Http::assertSent(fn (Request $request) => $request->url() === SLACK);
    Exceptions::assertReported(fn (NotificationFailed $e) => $e->getMessage() === 'Telegram refused the message: Bad Request: chat not found');
});

it('keeps the bot token out of connection errors', function () {
    Http::fake([TELEGRAM => fn () => throw new ConnectionException('cURL error 28 for '.TELEGRAM)]);
    config(['facebook-autopost.notifications.channels.telegram.enabled' => true]);

    expect(fn () => app(Notifier::class)->sendNow('telegram', Message::test()))
        ->toThrow(NotificationFailed::class, 'Could not reach Telegram: cURL error 28 for https://api.telegram.org/bot***/sendMessage');
});

it('keeps the Slack webhook out of connection errors', function () {
    Http::fake([SLACK => fn () => throw new ConnectionException('timed out: '.SLACK)]);

    expect(fn () => app(Notifier::class)->sendNow('slack', Message::test()))
        ->toThrow(NotificationFailed::class, 'Could not reach Slack: timed out: ***');
});

it('reports a share that gave up', function () {
    enableNotifications('slack');
    fakeFacebookAndChannels();

    event(new ShareFailed('post', 5, new RuntimeException('Facebook rejected the request: No')));

    Http::assertSent(fn (Request $request) => $request->url() === SLACK
        && str_contains($request['text'], '*❌ Facebook share failed*')
        && str_contains($request['text'], 'Model: post #5')
        && str_contains($request['text'], 'Error: Facebook rejected the request: No'));
});

it('warns when the token is about to expire', function (int $days, string $headline) {
    enableNotifications('slack');
    fakeFacebookAndChannels();
    $connection = new Connection(connected: true, status: 'active', name: 'Ada Lovelace');

    event(new TokenExpiringSoon($connection, $days));

    Http::assertSent(fn (Request $request) => $request->url() === SLACK
        && str_contains($request['text'], $headline)
        && str_contains($request['text'], 'Account: Ada Lovelace'));
})->with([
    'soon' => [3, 'The Facebook token expires in 3 days'],
    'expired' => [0, 'The Facebook token has expired'],
]);

it('queues one job per channel on the configured queue', function () {
    Queue::fake();
    enableNotifications('telegram', 'slack');
    config(['facebook-autopost.notifications.queue_connection' => 'redis', 'facebook-autopost.notifications.queue' => 'notifications']);
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Queue::assertPushed(SendNotification::class, 2);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job) => $job->channel === 'telegram'
        && $job->connection === 'redis' && $job->queue === 'notifications' && $job->tries === 3);
});

it('drops a queued message when notifications were switched off meanwhile', function () {
    enableNotifications('slack');
    Http::fake();
    $job = new SendNotification('slack', Message::test());
    config(['facebook-autopost.notifications.enabled' => false]);

    $job->handle(app(Notifier::class));

    Http::assertNothingSent();
});

it('sends to a channel of your own', function () {
    enableNotifications();
    config(['facebook-autopost.notifications.channels.custom' => ['enabled' => true, 'class' => RecordingChannel::class, 'prefix' => '>']]);
    fakeFacebookAndChannels();
    RecordingChannel::$sent = [];

    Facebook::share($this->post);

    expect(RecordingChannel::$sent)->toBe(['> Shared on Facebook: Fish & <Chips>']);
});

it('tests the enabled channels from the console', function () {
    enableNotifications('telegram', 'slack');
    fakeFacebookAndChannels();

    $this->artisan('facebook:test-notification')
        ->expectsOutputToContain('telegram: sent.')
        ->expectsOutputToContain('slack: sent.')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === SLACK && str_contains($request['text'], 'Facebook Autopost test notification'));
});

it('fails the console test when a channel is missing settings or refuses', function () {
    config(['facebook-autopost.notifications.channels.slack.enabled' => true, 'facebook-autopost.notifications.channels.slack.webhook_url' => null]);

    $this->artisan('facebook:test-notification')
        ->expectsOutputToContain('Notifications are off')
        ->expectsOutputToContain('slack: missing settings')
        ->assertFailed();

    Http::fake([TELEGRAM => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401)]);

    $this->artisan('facebook:test-notification', ['--channel' => ['telegram']])
        ->expectsOutputToContain('telegram: Telegram refused the message: Unauthorized')
        ->assertFailed();
});

it('says so when no channel is enabled', function () {
    $this->artisan('facebook:test-notification')
        ->expectsOutputToContain('No notification channel is enabled')
        ->assertFailed();
});

it('emails the configured addresses', function () {
    Mail::fake();
    enableNotifications('mail');
    config(['facebook-autopost.notifications.channels.mail.to' => 'ops@example.com, ceo@example.com, not-an-email']);
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) {
        $html = $mail->render();

        return $mail->hasTo('ops@example.com') && $mail->hasTo('ceo@example.com') && ! $mail->hasTo('not-an-email')
            && $mail->envelope()->subject === '✅ Shared on Facebook: Fish & <Chips>'
            && str_contains($html, '<strong>Shared on Facebook: Fish &amp; &lt;Chips&gt;</strong>')
            && str_contains($html, '<p>Post: https://www.facebook.com/1234567_7</p>')
            && str_contains($html, '<a href="https://example.com/posts/'.$this->post->id.'">');
    });
});

it('sends the email through the configured mailer', function () {
    Mail::fake();
    enableNotifications('mail');
    config([
        'facebook-autopost.notifications.channels.mail.to' => 'ops@example.com',
        'facebook-autopost.notifications.channels.mail.mailer' => 'array',
    ]);

    app(Notifier::class)->sendNow('mail', Message::test());

    Mail::mailer('array')->assertSent(NotificationMail::class);
});

it('skips the mail channel without a valid address', function () {
    Mail::fake();
    enableNotifications('mail');
    config(['facebook-autopost.notifications.channels.mail.to' => 'nobody']);
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Mail::assertNothingSent();
});

it('reports a mail transport failure without failing the share', function () {
    Exceptions::fake();
    enableNotifications('mail');
    config([
        'facebook-autopost.notifications.channels.mail.to' => 'ops@example.com',
        'facebook-autopost.notifications.channels.mail.mailer' => 'missing-mailer',
    ]);
    fakeFacebookAndChannels();

    expect(Facebook::share($this->post)->post_id)->toBe('1234567_7');
    Exceptions::assertReported(fn (NotificationFailed $e) => str_starts_with($e->getMessage(), 'Could not send the email: Mailer [missing-mailer] is not defined.'));
});

it('queues notifications by default', function () {
    expect(config('facebook-autopost.notifications.queued'))->toBeTrue();
});

it('sends at once, without a queue, when queued is off', function () {
    Queue::fake();
    enableNotifications('slack');
    config(['facebook-autopost.notifications.queued' => false]);
    fakeFacebookAndChannels();

    Facebook::share($this->post);

    Queue::assertNotPushed(SendNotification::class);
    Http::assertSent(fn (Request $request) => $request->url() === SLACK);
});

it('never fails the share when an unqueued channel fails', function () {
    Exceptions::fake();
    enableNotifications('slack');
    config(['facebook-autopost.notifications.queued' => false]);
    Http::fake([
        'https://graph.facebook.com/v25.0/1234567/feed' => Http::response(['id' => '1234567_7'], 200),
        SLACK => Http::response('invalid_token', 403),
    ]);

    expect(Facebook::share($this->post)->post_id)->toBe('1234567_7');
    Exceptions::assertReported(fn (NotificationFailed $e) => $e->getMessage() === 'Slack refused the message: invalid_token');
});
