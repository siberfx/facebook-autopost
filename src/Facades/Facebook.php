<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Facades;

use Illuminate\Support\Facades\Facade;
use Siberfx\FacebookAutopost\FacebookManager;

/**
 * @method static bool isConnected()
 * @method static \Siberfx\FacebookAutopost\Data\Connection connection()
 * @method static \Siberfx\FacebookAutopost\Models\FacebookShare share(\Illuminate\Database\Eloquent\Model&\Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook $model, string $trigger = 'manual')
 * @method static void queue(\Illuminate\Database\Eloquent\Model&\Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook $model)
 * @method static bool wasPosted(\Illuminate\Database\Eloquent\Model $model)
 * @method static bool disconnect()
 * @method static string authorizationUrl(string $state)
 * @method static \Siberfx\FacebookAutopost\Data\Connection completeConnection(string $code)
 *
 * @see FacebookManager
 */
final class Facebook extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return FacebookManager::class;
    }
}
