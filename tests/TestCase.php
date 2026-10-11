<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Siberfx\FacebookAutopost\Facades\Facebook;
use Siberfx\FacebookAutopost\FacebookAutopostServiceProvider;
use Siberfx\FacebookAutopost\Tests\Fixtures\Post;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        Relation::morphMap(['post' => Post::class]);

        $this->defineManageGate();
    }

    /** The package denies everyone by default; most tests act as an allowed admin. */
    protected function defineManageGate(): void
    {
        Gate::define(FacebookAutopostServiceProvider::ABILITY, fn ($user = null) => true);
    }

    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            class_exists(LivewireServiceProvider::class) ? LivewireServiceProvider::class : null,
            FacebookAutopostServiceProvider::class,
        ]));
    }

    protected function getPackageAliases($app): array
    {
        return ['Facebook' => Facebook::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('facebook-autopost.app_id', 'app-id');
        $app['config']->set('facebook-autopost.app_secret', 'app-secret');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    /** Laravel caches runningInConsole(); tests run in the CLI, so flip it to exercise the web path. */
    protected function pretendNotInConsole(): void
    {
        (fn () => $this->isRunningInConsole = false)->call($this->app);
    }
}
