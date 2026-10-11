<?php

declare(strict_types=1);

use Siberfx\FacebookAutopost\Notifications\Channels\MailChannel;
use Siberfx\FacebookAutopost\Notifications\Channels\SlackChannel;
use Siberfx\FacebookAutopost\Notifications\Channels\TelegramChannel;
use Siberfx\FacebookAutopost\Stores\DatabaseTokenStore;

return [

    /*
    | Credentials of your Facebook app (developers.facebook.com → My Apps → Settings → Basic).
    */
    'app_id' => env('FACEBOOK_APP_ID'),
    'app_secret' => env('FACEBOOK_APP_SECRET'),

    /*
    | The Page to post to. Empty: the first Page the connecting account manages.
    */
    'page_id' => env('FACEBOOK_PAGE_ID'),

    /*
    | Must equal one of the app's "Valid OAuth Redirect URIs" exactly. Null uses
    | the package callback route, e.g. https://example.com/facebook/callback.
    */
    'redirect_uri' => env('FACEBOOK_REDIRECT_URI'),

    /*
    | Graph API version. Facebook retires each version about two years after
    | release; move this forward when they announce a sunset.
    */
    'api_version' => env('FACEBOOK_API_VERSION', 'v25.0'),

    'routes' => [
        'enabled' => true,
        'prefix' => 'facebook',
        'name' => 'facebook-autopost.',
        // The gate denies everyone until your app defines it, e.g. in AppServiceProvider::boot():
        // Gate::define('manage-facebook-autopost', fn (User $user) => $user->is_admin);
        'middleware' => ['web', 'auth', 'can:manage-facebook-autopost'],
        // Where the OAuth callback sends the browser; ?facebook=connected|cancelled|error|… is appended.
        'after_connect' => '/',
    ],

    'autopost' => [
        'enabled' => (bool) env('FACEBOOK_AUTO_POST', false),
        // Seeders and Artisan commands do not auto-post unless this is true.
        'in_console' => false,
        'queue_connection' => null,
        'queue' => null,
    ],

    'ui' => [
        // false = headless: no views, Blade or Livewire components, and the
        // routes answer JSON only. Use the facade, events and endpoints yourself.
        'enabled' => (bool) env('FACEBOOK_UI', true),
        // tailwind | bootstrap | the name of a folder of published views
        'theme' => env('FACEBOOK_UI_THEME', 'tailwind'),
    ],

    /*
    | Messages sent to Telegram, Slack and/or email when a post goes out, an
    | automatic share gives up, or the token is about to expire
    | (facebook:check-token). A failing channel never fails the share.
    | Try your settings with: php artisan facebook:test-notification
    */
    'notifications' => [
        'enabled' => (bool) env('FACEBOOK_NOTIFY', false),

        'events' => [
            'shared' => true,
            'failed' => true,
            'token_expiring' => true,
        ],

        // true: one queued job per channel, retried `tries` times with `backoff`.
        // false: sent at once inside the request or job that shared, no retries.
        'queued' => (bool) env('FACEBOOK_NOTIFY_QUEUED', true),
        'queue_connection' => null,
        'queue' => null,
        'tries' => 3,
        'backoff' => [30, 120],

        'channels' => [
            'telegram' => [
                'enabled' => (bool) env('FACEBOOK_NOTIFY_TELEGRAM', false),
                'class' => TelegramChannel::class,
                // From @BotFather; add the bot to the chat first.
                'bot_token' => env('FACEBOOK_TELEGRAM_BOT_TOKEN'),
                // A user, group (-100…) or @channel id.
                'chat_id' => env('FACEBOOK_TELEGRAM_CHAT_ID'),
                // Forum topic id in a group with topics, or null.
                'thread_id' => env('FACEBOOK_TELEGRAM_THREAD_ID'),
            ],
            'slack' => [
                'enabled' => (bool) env('FACEBOOK_NOTIFY_SLACK', false),
                'class' => SlackChannel::class,
                // Incoming webhook URL (api.slack.com → Your apps → Incoming Webhooks).
                'webhook_url' => env('FACEBOOK_SLACK_WEBHOOK_URL'),
            ],
            'mail' => [
                'enabled' => (bool) env('FACEBOOK_NOTIFY_MAIL', false),
                'class' => MailChannel::class,
                // One address or several, comma separated: "ops@example.com,ceo@example.com".
                'to' => env('FACEBOOK_NOTIFY_MAIL_TO'),
                // A mailer from config/mail.php; null uses your default mailer and from address.
                'mailer' => env('FACEBOOK_NOTIFY_MAILER'),
            ],
            // Your own: 'teams' => ['enabled' => true, 'class' => App\TeamsChannel::class, …],
            // a class implementing Siberfx\FacebookAutopost\Contracts\NotificationChannel.
        ],
    ],

    'token_store' => DatabaseTokenStore::class,

    'http' => [
        'timeout' => 20,
    ],

    'job' => [
        'tries' => 3,
        'backoff' => [60, 300],
    ],

    'status_cache_ttl' => 300,

    'expiry_warning_days' => 7,

];
