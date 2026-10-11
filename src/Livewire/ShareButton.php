<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook;
use Siberfx\FacebookAutopost\Exceptions\FacebookRequestFailed;
use Siberfx\FacebookAutopost\Exceptions\NotConnected;
use Siberfx\FacebookAutopost\Exceptions\NotShareable;
use Siberfx\FacebookAutopost\FacebookAutopostServiceProvider;
use Siberfx\FacebookAutopost\FacebookManager;
use Siberfx\FacebookAutopost\Models\FacebookShare;
use Siberfx\FacebookAutopost\Support\ShareButtonPresenter;
use Siberfx\FacebookAutopost\Support\Theme;

final class ShareButton extends Component
{
    #[Locked]
    public string $shareableType = '';

    #[Locked]
    public string $shareableId = '';

    public ?string $label = null;

    public string $size = 'md';

    public bool $confirm = true;

    /** @var array{type: string, message: string}|null */
    public ?array $flash = null;

    public function mount(Model $model, ?string $label = null, string $size = 'md', bool $confirm = true): void
    {
        if (! $model instanceof ShareableOnFacebook) {
            throw new InvalidArgumentException($model::class.' must implement '.ShareableOnFacebook::class.'.');
        }

        $this->shareableType = $model->getMorphClass();
        $this->shareableId = (string) $model->getKey();
        $this->label = $label;
        $this->size = $size === 'sm' ? 'sm' : 'md';
        $this->confirm = $confirm;
    }

    public function share(FacebookManager $facebook): void
    {
        Gate::authorize(FacebookAutopostServiceProvider::ABILITY);

        try {
            $post = $facebook->share($this->model(), FacebookShare::TRIGGER_MANUAL);
        } catch (NotConnected|NotShareable|FacebookRequestFailed|InvalidArgumentException $e) {
            $this->flash = ['type' => 'error', 'message' => $e->getMessage()];

            return;
        }

        $this->flash = ['type' => 'success', 'message' => 'Shared on Facebook.'];
        $this->dispatch('facebook-autopost-shared', id: $post->post_id);
    }

    public function render(FacebookManager $facebook): View
    {
        return view(Theme::view('share-button'), [
            'presenter' => new ShareButtonPresenter($this->model(), $facebook, $this->label),
            'size' => $this->size,
            'confirm' => $this->confirm,
            'livewire' => true,
            'flash' => $this->flash,
        ]);
    }

    private function model(): Model&ShareableOnFacebook
    {
        $class = Relation::getMorphedModel($this->shareableType) ?? $this->shareableType;

        abort_if(! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnFacebook::class), 404);

        /** @var Model&ShareableOnFacebook */
        return $class::query()->findOrFail($this->shareableId);
    }
}
