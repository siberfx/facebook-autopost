<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Siberfx\FacebookAutopost\Contracts\TokenStore;
use Siberfx\FacebookAutopost\Events\Shared;
use Siberfx\FacebookAutopost\Events\ShareFailed;
use Siberfx\FacebookAutopost\Events\TokenExpiringSoon;
use Siberfx\FacebookAutopost\Listeners\SendNotifications;
use Siberfx\FacebookAutopost\Support\Ui;

final class FacebookAutopostServiceProvider extends ServiceProvider
{
    /** Who may connect, disconnect, see the connection and share. Define it in your app. */
    public const ABILITY = 'manage-facebook-autopost';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/facebook-autopost.php', 'facebook-autopost');

        $this->app->bind(
            TokenStore::class,
            fn ($app) => $app->make((string) config('facebook-autopost.token_store')),
        );

        $this->app->scoped(FacebookManager::class);
    }

    public function boot(): void
    {
        // Deny by default. Registered once every provider has booted, so the
        // app's own definition wins whatever the provider order.
        $this->app->booted(function (): void {
            if (! Gate::has(self::ABILITY)) {
                Gate::define(self::ABILITY, fn ($user = null): bool => false);
            }
        });

        // Always listening; the listener reads facebook-autopost.notifications on each event.
        Event::listen([Shared::class, ShareFailed::class, TokenExpiringSoon::class], SendNotifications::class);

        if (Ui::enabled()) {
            $this->bootUi();
        }

        if (config('facebook-autopost.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/facebook-autopost.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/facebook-autopost.php' => config_path('facebook-autopost.php'),
            ], 'facebook-autopost-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'facebook-autopost-migrations');

            $this->publishes([
                __DIR__.'/../stubs/ShareableModel.stub' => base_path('stubs/facebook-autopost/ShareableModel.stub'),
            ], 'facebook-autopost-stubs');

            $this->commands([
                Console\InstallCommand::class,
                Console\StatusCommand::class,
                Console\DisconnectCommand::class,
                Console\ShareCommand::class,
                Console\CheckTokenCommand::class,
                Console\TestNotificationCommand::class,
            ]);
        }
    }

    /** Views, Blade and Livewire components; skipped in headless mode (ui.enabled = false). */
    private function bootUi(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'facebook-autopost');
        Blade::componentNamespace('Siberfx\FacebookAutopost\View\Components', 'facebook-autopost');

        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component('facebook-autopost.connection', Livewire\ConnectionCard::class);
            \Livewire\Livewire::component('facebook-autopost.share-button', Livewire\ShareButton::class);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/facebook-autopost'),
            ], 'facebook-autopost-views');
        }
    }
}
