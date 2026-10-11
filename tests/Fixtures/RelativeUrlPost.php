<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook;
use Siberfx\FacebookAutopost\Posts\FacebookPost;

/** A live model whose toFacebookPost() throws: it builds a relative URL. */
final class RelativeUrlPost extends Model implements ShareableOnFacebook
{
    protected $table = 'posts';

    protected $guarded = [];

    public function isLiveForFacebook(): bool
    {
        return true;
    }

    public function toFacebookPost(): FacebookPost
    {
        return FacebookPost::make('/posts/'.$this->getKey(), (string) $this->title);
    }
}
