<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Siberfx\FacebookAutopost\Concerns\PostsToFacebook;
use Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook;
use Siberfx\FacebookAutopost\Posts\FacebookPost;

final class Post extends Model implements ShareableOnFacebook
{
    use PostsToFacebook;

    protected $guarded = [];

    protected $casts = ['status' => PostStatus::class];

    public function isLiveForFacebook(): bool
    {
        return $this->status === PostStatus::Published;
    }

    public function toFacebookPost(): FacebookPost
    {
        return FacebookPost::make('https://example.com/posts/'.$this->getKey(), (string) $this->title)
            ->description('About '.$this->title);
    }
}
