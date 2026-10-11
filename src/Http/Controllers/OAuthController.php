<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Http\Controllers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Siberfx\FacebookAutopost\Exceptions\NotConfigured;
use Siberfx\FacebookAutopost\FacebookManager;
use Throwable;

final class OAuthController extends Controller
{
    private const SESSION_KEY = 'facebook-autopost.state';

    public function __construct(private readonly FacebookManager $facebook) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);

        try {
            $url = $this->facebook->authorizationUrl($state);
        } catch (NotConfigured) {
            return $this->back('not_configured');
        }

        $request->session()->put(self::SESSION_KEY, $state);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            return $this->back('invalid_state');
        }

        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            // Facebook sends error_reason=user_denied when the person clicks Cancel.
            if ($request->query('error_reason') === 'user_denied') {
                return $this->back('cancelled');
            }

            $reason = $request->query('error_description');

            return $this->back('error', ['reason' => Str::limit(is_string($reason) && $reason !== '' ? $reason : $error, 300)]);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->back('missing_code');
        }

        try {
            $this->facebook->completeConnection($code);
        } catch (Throwable $e) {
            Log::warning('Facebook token exchange failed.', [
                'error' => $e instanceof RequestException ? $e->response->body() : $e->getMessage(),
            ]);

            return $this->back('exchange_failed');
        }

        return $this->back('connected');
    }

    /** @param  array<string, string>  $extra */
    private function back(string $status, array $extra = []): RedirectResponse
    {
        $target = (string) config('facebook-autopost.routes.after_connect', '/');
        $glue = str_contains($target, '?') ? '&' : '?';

        return redirect()->to($target.$glue.http_build_query(['facebook' => $status] + $extra));
    }
}
