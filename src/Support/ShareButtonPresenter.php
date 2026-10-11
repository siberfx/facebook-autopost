<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook;
use Siberfx\FacebookAutopost\FacebookAutopostServiceProvider;
use Siberfx\FacebookAutopost\FacebookManager;
use Siberfx\FacebookAutopost\Models\FacebookShare;

final class ShareButtonPresenter
{
    private ?CarbonImmutable $lastPostedAt = null;

    private bool $resolved = false;

    public function __construct(
        private readonly Model&ShareableOnFacebook $model,
        private readonly FacebookManager $facebook,
        private readonly ?string $label = null,
    ) {}

    public function lastPostedAt(): ?CarbonImmutable
    {
        if (! $this->resolved) {
            $this->lastPostedAt = FacebookShare::query()->for($this->model)->posted()->latest('posted_at')->first()?->posted_at;
            $this->resolved = true;
        }

        return $this->lastPostedAt;
    }

    public function disabledReason(): ?string
    {
        if (Gate::denies(FacebookAutopostServiceProvider::ABILITY)) {
            return 'You are not allowed to manage Facebook.';
        }

        if (! $this->facebook->isConnected()) {
            return 'Connect Facebook first.';
        }

        return $this->model->isLiveForFacebook() ? null : 'Only live items can be shared.';
    }

    public function text(): string
    {
        return $this->label ?? ($this->lastPostedAt() !== null ? 'Share again' : 'Share on Facebook');
    }

    public function title(): string
    {
        $posted = $this->lastPostedAt();

        return $this->disabledReason()
            ?? ($posted !== null ? 'Shared on Facebook on '.$posted->format('j M Y').'. Share again.' : 'Share on Facebook');
    }

    public function confirmText(): string
    {
        $posted = $this->lastPostedAt();

        return $posted !== null
            ? 'This was already posted on '.$posted->format('j M Y').'. Post it to Facebook again?'
            : 'Post this to Facebook now?';
    }

    public function signedAction(): string
    {
        return URL::signedRoute(config('facebook-autopost.routes.name', 'facebook-autopost.').'share.signed', [
            'type' => $this->model->getMorphClass(),
            'id' => $this->model->getKey(),
        ]);
    }
}
