<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

final class StatusMessages
{
    public const FLASH_KEY = 'facebook-autopost.flash';

    /** @return array{type: string, message: string}|null */
    public static function fromCallback(?string $status, ?string $reason = null): ?array
    {
        return match ($status) {
            'connected' => ['type' => 'success', 'message' => 'Facebook connected.'],
            'cancelled' => ['type' => 'error', 'message' => 'Facebook connection cancelled.'],
            'error' => ['type' => 'error', 'message' => 'Facebook refused the connection: '.($reason !== null && $reason !== '' ? $reason : 'unknown error')],
            'exchange_failed' => ['type' => 'error', 'message' => 'Facebook refused the token exchange. Check that the redirect URL is registered on the Facebook app exactly.'],
            'invalid_state' => ['type' => 'error', 'message' => 'The Facebook login expired. Connect again.'],
            'missing_code' => ['type' => 'error', 'message' => 'Facebook did not return an authorization code.'],
            'not_configured' => ['type' => 'error', 'message' => 'Set FACEBOOK_CLIENT_ID and FACEBOOK_CLIENT_SECRET first.'],
            default => null,
        };
    }

    /** @return array{type: string, message: string}|null */
    public static function current(Request $request): ?array
    {
        $flash = self::session($request)?->get(self::FLASH_KEY);

        if (is_array($flash) && isset($flash['type'], $flash['message'])) {
            return ['type' => (string) $flash['type'], 'message' => (string) $flash['message']];
        }

        $status = $request->query('facebook');
        $reason = $request->query('reason');

        return self::fromCallback(is_string($status) ? $status : null, is_string($reason) ? $reason : null);
    }

    private static function session(Request $request): ?Session
    {
        if ($request->hasSession()) {
            return $request->session();
        }

        return app()->bound('session.store') ? app('session.store') : null;
    }
}
