<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Component;
use Siberfx\FacebookAutopost\FacebookAutopostServiceProvider;
use Siberfx\FacebookAutopost\FacebookManager;
use Siberfx\FacebookAutopost\Support\ConnectionPresenter;
use Siberfx\FacebookAutopost\Support\StatusMessages;
use Siberfx\FacebookAutopost\Support\Theme;

final class ConnectionCard extends Component
{
    /** @var array{type: string, message: string}|null */
    public ?array $flash = null;

    public function mount(): void
    {
        $this->flash = StatusMessages::current(request());
    }

    public function disconnect(FacebookManager $facebook): void
    {
        Gate::authorize(FacebookAutopostServiceProvider::ABILITY);

        $revoked = $facebook->disconnect();

        $this->flash = ['type' => 'success', 'message' => $revoked
            ? 'Facebook disconnected.'
            : 'Facebook disconnected here, but Facebook did not confirm revoking the token.'];
    }

    public function render(FacebookManager $facebook): View
    {
        $connection = $facebook->connection();
        $routes = (string) config('facebook-autopost.routes.name', 'facebook-autopost.');

        return view(Theme::view('connection'), [
            'connection' => $connection,
            'presenter' => new ConnectionPresenter($connection),
            'flash' => $this->flash,
            'livewire' => true,
            'attributes' => new ComponentAttributeBag,
            'canManage' => Gate::allows(FacebookAutopostServiceProvider::ABILITY),
            'connectUrl' => route($routes.'redirect'),
            'disconnectUrl' => route($routes.'connection.destroy'),
        ]);
    }
}
