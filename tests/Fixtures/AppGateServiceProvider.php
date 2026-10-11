<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Stands in for an app's AppServiceProvider: only the user named "Admin" may manage Facebook. */
final class AppGateServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('manage-facebook-autopost', fn (Authenticatable $user) => $user->getAuthIdentifier() === 1);
    }
}
