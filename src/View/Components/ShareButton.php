<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;
use InvalidArgumentException;
use Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook;
use Siberfx\FacebookAutopost\FacebookManager;
use Siberfx\FacebookAutopost\Support\ShareButtonPresenter;
use Siberfx\FacebookAutopost\Support\Theme;

final class ShareButton extends Component
{
    public function __construct(
        public Model $model,
        public ?string $label = null,
        public string $size = 'md',
        public bool $confirm = true,
    ) {
        if (! $model instanceof ShareableOnFacebook) {
            throw new InvalidArgumentException($model::class.' must implement '.ShareableOnFacebook::class.'.');
        }
    }

    public function render(): View
    {
        /** @var Model&ShareableOnFacebook $model */
        $model = $this->model;

        return view(Theme::view('share-button'), [
            'presenter' => new ShareButtonPresenter($model, app(FacebookManager::class), $this->label),
            'size' => $this->size === 'sm' ? 'sm' : 'md',
            'confirm' => $this->confirm,
            'livewire' => false,
            'flash' => null,
        ]);
    }
}
