<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Stores;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Data\StoredConnection;
use Siberfx\FacebookAutopost\Models\FacebookConnection;

final class DatabaseTokenStore implements TokenStore
{
    public function get(): ?StoredConnection
    {
        $row = FacebookConnection::query()->latest('id')->first();

        if ($row === null) {
            return null;
        }

        try {
            $token = $row->access_token;
            $userToken = $row->user_access_token;
        } catch (DecryptException) { // @phpstan-ignore catch.neverThrown (thrown by the encrypted cast on property access)
            // APP_KEY changed since connecting: the token is unreadable, so
            // the app is effectively disconnected and must connect again.
            return null;
        }

        if ($token === '') {
            return null;
        }

        return new StoredConnection(
            accessToken: $token,
            pageId: $row->page_id,
            name: $row->name,
            picture: $row->picture,
            userAccessToken: $userToken,
            expiresAt: $row->expires_at,
            connectedAt: $row->connected_at,
        );
    }

    public function put(StoredConnection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            FacebookConnection::query()->delete();

            FacebookConnection::query()->create([
                'access_token' => $connection->accessToken,
                'user_access_token' => $connection->userAccessToken,
                'page_id' => $connection->pageId,
                'name' => $connection->name,
                'picture' => $connection->picture,
                'expires_at' => $connection->expiresAt,
                'connected_at' => $connection->connectedAt,
            ]);
        });
    }

    public function forget(): void
    {
        FacebookConnection::query()->delete();
    }
}
