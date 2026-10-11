<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use Siberfx\FacebookAutopost\FacebookAutopostServiceProvider;
use Siberfx\FacebookAutopost\FacebookManager;
use Siberfx\FacebookAutopost\Support\ConnectionPresenter;
use Siberfx\FacebookAutopost\Support\StatusMessages;
use Siberfx\FacebookAutopost\Support\Theme;

final class Connection extends Component
{
    public function render(): View
    {
        $connection = app(FacebookManager::class)->connection();
        $routes = (string) config('facebook-autopost.routes.name', 'facebook-autopost.');

        return view(Theme::view('connection'), [
            'connection' => $connection,
            'presenter' => new ConnectionPresenter($connection),
            'flash' => StatusMessages::current(request()),
            'livewire' => false,
            'canManage' => Gate::allows(FacebookAutopostServiceProvider::ABILITY),
            'connectUrl' => route($routes.'redirect'),
            'disconnectUrl' => route($routes.'connection.destroy'),
        ]);
    }
}
