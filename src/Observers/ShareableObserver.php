<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Observers;

use Illuminate\Database\Eloquent\Model;
use Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook;
use Siberfx\FacebookAutopost\FacebookManager;

final class ShareableObserver
{
    public function saved(Model $model): void
    {
        if (! $model instanceof ShareableOnFacebook || ! config('facebook-autopost.autopost.enabled')) {
            return;
        }

        if (app()->runningInConsole() && ! config('facebook-autopost.autopost.in_console')) {
            return;
        }

        if (! $model->isLiveForFacebook() || ! $this->justBecameLive($model)) {
            return;
        }

        $facebook = app(FacebookManager::class);

        if (! $facebook->isConnected() || $facebook->wasPosted($model)) {
            return;
        }

        $facebook->queue($model);
    }

    /**
     * Inside "saved" the original attributes still hold the values from
     * before this save. Evaluate the model's own rule on them, so any live
     * rule (enum, boolean, dates) works without naming columns.
     */
    private function justBecameLive(Model&ShareableOnFacebook $model): bool
    {
        $before = $model->getRawOriginal();

        if ($before === []) {
            return true; // a fresh insert
        }

        $previous = (clone $model)->setRawAttributes($before);

        return ! $previous->isLiveForFacebook();
    }
}
