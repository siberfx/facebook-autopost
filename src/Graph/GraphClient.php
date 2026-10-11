<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Graph;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Exceptions\FacebookRequestFailed;
use Siberfx\FacebookAutopost\Exceptions\NotConfigured;
use Siberfx\FacebookAutopost\Posts\FacebookPost;
use Throwable;

/**
 * Everything that talks to Facebook: the Login dialog, the token exchange,
 * the Page lookup, debug_token, posting to a Page feed and revoking the app.
 */
final class GraphClient
{
    /** Facebook Login scopes needed to list a Page and post to it. */
    public const LOGIN_SCOPES = ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement'];

    /** Sent on both the dialog request and the code exchange, so they always match. */
    public function redirectUri(): string
    {
        $configured = config('facebook-autopost.redirect_uri');

        return is_string($configured) && $configured !== ''
            ? $configured
            : route(config('facebook-autopost.routes.name', 'facebook-autopost.').'callback');
    }

    public function authorizationUrl(string $state): string
    {
        [$appId] = $this->credentials();

        return 'https://www.facebook.com/'.$this->version().'/dialog/oauth?'.http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'response_type' => 'code',
            'scope' => implode(',', self::LOGIN_SCOPES),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the login code for a long-lived user token, then take the Page
     * token of the Page to post to: the configured page_id when set, otherwise
     * the first Page the account manages. Page tokens derived from a long-lived
     * user token do not expire, so the returned connection has no expiry.
     *
     * @throws FacebookRequestFailed
     * @throws NotConfigured
     */
    public function exchange(string $code): StoredConnection
    {
        [$appId, $appSecret] = $this->credentials();

        $short = $this->tokenFrom($this->send(fn () => $this->graph()->get('oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
        ])), 'short-lived user token');

        $longResponse = $this->send(fn () => $this->graph()->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $short,
        ]));
        $long = $this->tokenFrom($longResponse, 'long-lived user token');

        $pages = $this->send(fn () => $this->graph()->withToken($long)->get('me/accounts', [
            'fields' => 'id,name,access_token',
            'limit' => 100,
        ]))->json('data');

        $configured = $this->credential('page_id');
        $page = collect(is_array($pages) ? $pages : [])->first(
            fn ($candidate) => is_array($candidate) && ($configured === null || (string) ($candidate['id'] ?? '') === $configured),
        );

        if (! is_array($page) || ! is_string($page['access_token'] ?? null) || ($page['id'] ?? '') === '') {
            throw new FacebookRequestFailed($configured !== null
                ? "This Facebook account does not manage the Page {$configured}."
                : 'This Facebook account does not manage a Page.');
        }

        return new StoredConnection(
            accessToken: $page['access_token'],
            pageId: (string) $page['id'],
            name: is_string($page['name'] ?? null) ? $page['name'] : null,
            userAccessToken: $long,
            connectedAt: CarbonImmutable::now(),
        );
    }

    /**
     * Facebook's view of a token: validity, expiry (0 = never) and scopes.
     *
     * @return array<string, mixed>|null Null when Facebook cannot be asked.
     */
    public function debugToken(string $token): ?array
    {
        $data = $this->jsonOrNull(fn () => $this->graph()->get('debug_token', [
            'input_token' => $token,
            'access_token' => $this->appToken(),
        ]));

        $inner = $data['data'] ?? null;

        return is_array($inner) ? $inner : null;
    }

    /** @return array<string, mixed>|null The Page's name and picture, or null when Facebook cannot answer. */
    public function pageProfile(string $pageToken, string $pageId): ?array
    {
        return $this->jsonOrNull(fn () => $this->graph()->withToken($pageToken)->get($pageId, [
            'fields' => 'name,picture.width(128).height(128)',
        ]));
    }

    /**
     * Post a link to the Page feed.
     *
     * @return string|null The post id; null when Facebook accepted the post without returning its id.
     *
     * @throws FacebookRequestFailed
     */
    public function postLink(string $pageToken, string $pageId, FacebookPost $post): ?string
    {
        $response = $this->send(fn () => $this->graph()->withToken($pageToken)->post("{$pageId}/feed", [
            'message' => $post->commentaryText(),
            'link' => $post->url(),
        ]));

        // A 2xx means the post exists; never fail it for a missing id, or a retry would post twice.
        $id = $response->json('id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Remove the app's permissions for the user, which revokes the Page token
     * too. Best effort: false when Facebook refuses or cannot be reached.
     */
    public function revoke(string $userToken): bool
    {
        try {
            return $this->graph()->withToken($userToken)->delete('me/permissions')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  Closure(): Response  $request
     *
     * @throws FacebookRequestFailed
     */
    private function send(Closure $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new FacebookRequestFailed('Could not reach Facebook: '.$e->getMessage(), 0);
        }

        if ($response->failed()) {
            throw FacebookRequestFailed::fromResponse($response);
        }

        return $response;
    }

    /** @throws FacebookRequestFailed */
    private function tokenFrom(Response $response, string $what): string
    {
        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new FacebookRequestFailed("Facebook returned no {$what}.", $response->status());
        }

        return $token;
    }

    /** @return array{0: string, 1: string} */
    private function credentials(): array
    {
        $id = $this->credential('app_id');
        $secret = $this->credential('app_secret');

        if ($id === null || $secret === null) {
            throw new NotConfigured;
        }

        return [$id, $secret];
    }

    private function appToken(): string
    {
        [$id, $secret] = $this->credentials();

        return $id.'|'.$secret;
    }

    private function credential(string $key): ?string
    {
        $value = config('facebook-autopost.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function graph(): PendingRequest
    {
        return Http::baseUrl('https://graph.facebook.com/'.$this->version())
            ->timeout((int) config('facebook-autopost.http.timeout', 20))
            ->acceptJson();
    }

    private function version(): string
    {
        return (string) (config('facebook-autopost.api_version') ?: 'v25.0');
    }

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>|null
     */
    private function jsonOrNull(callable $request): ?array
    {
        try {
            $response = $request();
        } catch (Throwable) {
            return null;
        }

        $json = $response->successful() ? $response->json() : null;

        return is_array($json) ? $json : null;
    }
}
