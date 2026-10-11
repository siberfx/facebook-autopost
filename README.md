# Facebook Autopost for Laravel

Connect one Facebook Page to your Laravel app and share your models to it — automatically when they
go live, or whenever you choose. Posts go to the Page feed through the Graph API as link posts.

- One Facebook **Page** per app, connected by an admin with Facebook Login.
- Auto-post when a model becomes live, at most once, off until you switch it on.
- Ready-made connection card and share button (Tailwind or Bootstrap 5, Blade or Livewire), plus routes, JSON endpoints, Artisan commands and events if you prefer to build your own.
- Headless mode (`FACEBOOK_UI=false`) when you want the package without any UI.
- Optional Telegram, Slack and email messages on every post, failed share and expiring token.

Requires PHP 8.4+ and Laravel 12 or 13.

## Contents

- [1. Set up the Facebook app](#1-set-up-the-facebook-app)
- [2. Install](#2-install)
- [3. Connect](#3-connect)
- [4. Make a model shareable](#4-make-a-model-shareable)
- [5. Share on demand](#5-share-on-demand)
- [6. Ready-made UI](#6-ready-made-ui)
- [7. Events](#7-events)
- [8. Commands](#8-commands)
- [9. Notifications](#9-notifications)
- [10. Headless: no UI](#10-headless-no-ui)
- [11. Configuration reference](#11-configuration-reference)
- [12. Troubleshooting](#12-troubleshooting)

## 1. Set up the Facebook app

1. Create a **Business** app at <https://developers.facebook.com/apps> (use the *Other* or *Business* type).
2. Add the **Facebook Login** product. Under its settings, add your callback URL to **Valid OAuth Redirect URIs**, exactly as the app will send it, e.g. `https://example.com/facebook/callback`. Add one per environment.
3. Copy the **App ID** and **App Secret** from **Settings → Basic**.
4. Permissions: the connecting account must be an admin of the Page. The app needs `pages_show_list`, `pages_manage_posts` and `pages_read_engagement`. While the app is in Development mode these work for people with a role on the app; for other admins, submit the app for App Review.

> **"URL Blocked: This redirect failed because the redirect URI is not whitelisted"** on Facebook's page means the URL sent is not in *Valid OAuth Redirect URIs*. Run `php artisan route:list --name=facebook-autopost.callback`, or set `FACEBOOK_REDIRECT_URI` to the URL you registered.

## 2. Install

```bash
composer require siberfx/facebook-autopost
php artisan facebook:install          # publishes config + migrations, asks tailwind or bootstrap
php artisan migrate
```

`facebook:install` options:

```bash
php artisan facebook:install --theme=bootstrap   # no question asked
php artisan facebook:install --headless          # no UI at all, see section 10
```

To publish pieces by hand:

```bash
php artisan vendor:publish --tag=facebook-autopost-config
php artisan vendor:publish --tag=facebook-autopost-migrations
php artisan vendor:publish --tag=facebook-autopost-views    # only when the UI is enabled
php artisan vendor:publish --tag=facebook-autopost-stubs
```

`.env`:

```dotenv
FACEBOOK_APP_ID=...
FACEBOOK_APP_SECRET=...
# FACEBOOK_PAGE_ID=...          # only when the account manages several Pages
# FACEBOOK_REDIRECT_URI=https://example.com/facebook/callback   # only if it differs from the route
# FACEBOOK_AUTO_POST=true       # switch on when ready
FACEBOOK_UI_THEME=tailwind      # or bootstrap
```

**Required: say who may manage Facebook.** Connecting, disconnecting, seeing the connection and
sharing are all guarded by the `manage-facebook-autopost` gate. The package denies everyone until
your app defines it, for example in `AppServiceProvider::boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('manage-facebook-autopost', fn (User $user) => $user->is_admin);
```

The routes sit behind `['web', 'auth', 'can:manage-facebook-autopost']`, and the Livewire actions check
the same gate.

## 3. Connect

Send an admin to `route('facebook-autopost.redirect')`. After Facebook's consent screen they land on
`after_connect` with one of these query values:

| `?facebook=` | Meaning |
|---|---|
| `connected` | Done. |
| `cancelled` | The person clicked Cancel on Facebook. |
| `error&reason=…` | Facebook refused, e.g. a permission that is not granted. |
| `exchange_failed` | The code could not be exchanged; the reason is in your log. Usually a redirect URI mismatch. |
| `invalid_state` | The login attempt expired or was replayed. Connect again. |
| `missing_code` | Facebook came back without an authorization code. Connect again. |
| `not_configured` | App ID or App Secret missing. |

The connection card and `<x-facebook-autopost::flash />` turn these into messages for you.

The Page is chosen when connecting: `FACEBOOK_PAGE_ID` when set, otherwise the first Page the account
manages. The Page access token is stored encrypted. Page tokens derived from a long-lived user token do
not expire, so the package never refreshes them; if Facebook revokes the app, the status turns
`expired` and you connect again.

```php
use Siberfx\FacebookAutopost\Facades\Facebook;

if (Facebook::isConnected()) {
    $connection = Facebook::connection();

    $connection->name;        // "Siberfx Page"
    $connection->pageId;      // "1234567890"
    $connection->status;      // active | expired | unknown
    $connection->expiresAt;   // CarbonImmutable|null (null: the token does not expire)
    $connection->toArray();   // everything above as an array, never the token
}
```

`connection()` asks Facebook (`debug_token` and the Page's name and picture) at most once every
`status_cache_ttl` seconds (300 by default). `isConnected()` only checks that a token is stored.

## 4. Make a model shareable

```php
use Illuminate\Support\Str;
use Siberfx\FacebookAutopost\Concerns\PostsToFacebook;
use Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook;
use Siberfx\FacebookAutopost\Posts\FacebookPost;

class Article extends Model implements ShareableOnFacebook
{
    use PostsToFacebook;

    public function isLiveForFacebook(): bool
    {
        return $this->status === 'published';
    }

    public function toFacebookPost(): FacebookPost
    {
        return FacebookPost::make(url: route('blog.show', $this), title: $this->title)
            ->commentary("New on the blog: {title}\n\n{url}");
    }
}
```

- **`isLiveForFacebook()`** — whether the public can see the model right now. It decides when auto-post fires (the save on which it turns from `false` to `true`) and whether a manual share is allowed.
- **`toFacebookPost()`** — what to post. `FacebookPost::make($url, $title)` is the link; `commentary()` is the message, where `{title}` and `{url}` are filled in (default: `{title}`).

| `FacebookPost` | Notes |
|---|---|
| `make(string $url, string $title)` | Must be an absolute http(s) URL, or `InvalidArgumentException`. Non-ASCII paths and hosts are normalised. |
| `->commentary(string $template)` | The message. Default `{title}`. |
| `->description(string $text)` | Kept for parity with the other social packages. Facebook builds the link preview from the page's own Open Graph tags, so this text is not sent. |

`FacebookPost` is immutable: each method returns a copy.

With `FACEBOOK_AUTO_POST=true`, an `Article` is queued for Facebook when it is **created published** or
**changes from draft to published** — once. Editing a published article does not post again. Seeders and
Artisan commands do not auto-post (set `autopost.in_console` to allow it). Mass `update()` queries and
`saveQuietly()` do not fire model events, so they do not auto-post.

Run a queue worker. The job is dispatched after the database transaction commits, is unique per model
while it waits, and retries when Facebook is busy or unreachable (429, 5xx, network). A request Facebook
refuses outright (400 with an invalid token, 403 …) fails at once, without retries.

## 5. Share on demand

```php
use Siberfx\FacebookAutopost\Facades\Facebook;

Facebook::share($article);           // now; returns the FacebookShare record (re-sharing is allowed)
Facebook::queue($article);           // in the background
Facebook::wasPosted($article);       // bool, same as $article->wasPostedToFacebook()
$article->facebookShares;            // history: post_id, status, trigger, error, posted_at
```

`share()` throws `NotConnected`, `NotShareable` (not live), `FacebookRequestFailed` (Facebook's message,
with `isUnauthorized()` and `isPermanent()`), or `InvalidArgumentException` (an invalid URL). A post
Facebook accepted without returning its id is recorded as posted with a `null` `post_id`.

## 6. Ready-made UI

```blade
{{-- resources/views/admin/settings.blade.php --}}
<x-facebook-autopost::connection />

{{-- next to a model --}}
<x-facebook-autopost::share-button :model="$article" />

{{-- or, with Livewire --}}
<livewire:facebook-autopost.connection />
<livewire:facebook-autopost.share-button :model="$article" />
```

Everything is rendered with `resources/views/tailwind` or `resources/views/bootstrap` (`FACEBOOK_UI_THEME`).

## 7. Events

| Event | When | Properties |
|---|---|---|
| `Connected` | A connection is completed | `Connection $connection` |
| `Disconnected` | The connection is removed | `bool $revoked` |
| `Shared` | A post went out | `$shareable`, `FacebookShare $post` |
| `ShareFailed` | An automatic share gave up | `$shareableType`, `$shareableId`, `$exception` |
| `TokenExpiringSoon` | `facebook:check-token` found an expired or nearly expired token | `Connection $connection`, `int $daysLeft` |

## 8. Commands

```bash
php artisan facebook:status              # the connected Page and its token status
php artisan facebook:check-token         # fires TokenExpiringSoon within --days (default 7)
php artisan facebook:disconnect --force  # forget the connection and revoke the app at Facebook
php artisan facebook:share article 5 --force   # share a model from the console (morph alias, id)
php artisan facebook:test-notification   # send a test message to the enabled channels
```

Schedule `facebook:check-token` daily if you want an alert before a token expires.

## 9. Notifications

Messages to Telegram, Slack and/or email when a post goes out, an automatic share gives up, or the
token is about to expire. A failing channel never fails the share.

```dotenv
FACEBOOK_NOTIFY=true
FACEBOOK_NOTIFY_TELEGRAM=true
FACEBOOK_TELEGRAM_BOT_TOKEN=...
FACEBOOK_TELEGRAM_CHAT_ID=...
FACEBOOK_NOTIFY_SLACK=true
FACEBOOK_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
FACEBOOK_NOTIFY_MAIL=true
FACEBOOK_NOTIFY_MAIL_TO=ops@example.com,ceo@example.com
```

Your own channel implements `Siberfx\FacebookAutopost\Contracts\NotificationChannel` and is listed under
`notifications.channels` in the config.

## 10. Headless: no UI

`FACEBOOK_UI=false` (or `facebook:install --headless`) registers no views, Blade or Livewire components,
and the routes answer JSON only. Use the facade, the events and the JSON endpoints yourself:

```text
GET    /facebook/connection       status (no token)
DELETE /facebook/connection       disconnect
POST   /facebook/share/{type}/{id}   share, 201 on success
```

## 11. Configuration reference

| Key | Env | Default | Notes |
|---|---|---|---|
| `app_id` | `FACEBOOK_APP_ID` | — | Required to connect. |
| `app_secret` | `FACEBOOK_APP_SECRET` | — | Required to connect. |
| `page_id` | `FACEBOOK_PAGE_ID` | `null` | The first managed Page when empty. |
| `redirect_uri` | `FACEBOOK_REDIRECT_URI` | `null` | The package callback route when empty. |
| `api_version` | `FACEBOOK_API_VERSION` | `v25.0` | Graph API version. |
| `autopost.enabled` | `FACEBOOK_AUTO_POST` | `false` | |
| `autopost.queue` | — | `null` | Queue name for share jobs. |
| `ui.enabled` | `FACEBOOK_UI` | `true` | `false` is headless. |
| `ui.theme` | `FACEBOOK_UI_THEME` | `tailwind` | `tailwind`, `bootstrap`, or a published folder. |
| `notifications.*` | `FACEBOOK_NOTIFY*` | off | See section 9. |
| `status_cache_ttl` | — | `300` | Seconds. |
| `expiry_warning_days` | — | `7` | Used by `facebook:check-token`. |

## 12. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `URL Blocked … redirect URI is not whitelisted` | Add the callback to *Valid OAuth Redirect URIs* exactly. |
| `This Facebook account does not manage the Page …` | Connect with an admin of the Page set in `FACEBOOK_PAGE_ID`. |
| Share fails with `Invalid OAuth access token` (code 190) | The app was removed or the Page token was revoked. Connect again. |
| Share fails with `(#200) …` or `(#10) …` | A permission is missing. Check the app's permissions and App Review status. |
| Status says `unknown` | Facebook could not be asked; the stored name is shown until it can. |
| Auto-post does nothing | `FACEBOOK_AUTO_POST=true`? A queue worker running? Saved through Eloquent (not a mass `update()`)? |
