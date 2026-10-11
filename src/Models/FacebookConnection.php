<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $access_token
 * @property string|null $user_access_token
 * @property string $page_id
 * @property string|null $name
 * @property string|null $picture
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $connected_at
 */
final class FacebookConnection extends Model
{
    protected $table = 'facebook_connections';

    protected $guarded = [];

    protected $hidden = ['access_token', 'user_access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'user_access_token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'connected_at' => 'immutable_datetime',
        ];
    }
}
