<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Siberfx\FacebookAutopost\Models\FacebookShare;
use Siberfx\FacebookAutopost\Observers\ShareableObserver;

/** For models implementing ShareableOnFacebook: auto-post and share history. */
trait PostsToFacebook
{
    public static function bootPostsToFacebook(): void
    {
        // Not static::observe(): that instantiates the model, which throws
        // while the model is still booting.
        static::saved(static fn ($model) => app(ShareableObserver::class)->saved($model));
    }

    /** @return MorphMany<FacebookShare, $this> */
    public function facebookShares(): MorphMany
    {
        return $this->morphMany(FacebookShare::class, 'shareable');
    }

    public function wasPostedToFacebook(): bool
    {
        return $this->facebookShares()->where('status', FacebookShare::STATUS_POSTED)->exists();
    }
}
