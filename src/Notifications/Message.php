<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Notifications;

use Illuminate\Database\Eloquent\Model;
use Siberfx\FacebookAutopost\Contracts\ShareableOnFacebook;
use Siberfx\FacebookAutopost\Events\Shared;
use Siberfx\FacebookAutopost\Events\ShareFailed;
use Siberfx\FacebookAutopost\Events\TokenExpiringSoon;
use Throwable;

/** Plain text a channel formats its own way: a headline and lines below it. Holds only strings, so it queues cleanly. */
final class Message
{
    public const LEVEL_SUCCESS = 'success';

    public const LEVEL_ERROR = 'error';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_INFO = 'info';

    /** @param  list<string>  $lines */
    public function __construct(
        public readonly string $level,
        public readonly string $headline,
        public readonly array $lines = [],
    ) {}

    public static function shared(Shared $event): self
    {
        [$title, $url] = self::describe($event->shareable);
        $id = $event->post->post_id;

        return new self(self::LEVEL_SUCCESS, 'Shared on Facebook: '.$title, array_values(array_filter([
            $url,
            $id !== null ? 'Post: https://www.facebook.com/'.$id : null,
            'Trigger: '.$event->post->trigger,
        ])));
    }

    public static function failed(ShareFailed $event): self
    {
        return new self(self::LEVEL_ERROR, 'Facebook share failed', [
            "Model: {$event->shareableType} #{$event->shareableId}",
            'Error: '.mb_substr($event->exception->getMessage(), 0, 500),
        ]);
    }

    public static function tokenExpiring(TokenExpiringSoon $event): self
    {
        $headline = $event->daysLeft > 0
            ? "The Facebook token expires in {$event->daysLeft} days"
            : 'The Facebook token has expired';

        return new self(self::LEVEL_WARNING, $headline, array_values(array_filter([
            $event->connection->name !== null ? 'Account: '.$event->connection->name : null,
            'Connect again to keep posting.',
        ])));
    }

    public static function test(): self
    {
        return new self(self::LEVEL_INFO, 'Facebook Autopost test notification', [
            'Notifications from '.config('app.name', 'Laravel').' reach this channel.',
        ]);
    }

    public function emoji(): string
    {
        return match ($this->level) {
            self::LEVEL_SUCCESS => '✅',
            self::LEVEL_ERROR => '❌',
            self::LEVEL_WARNING => '⚠️',
            default => 'ℹ️',
        };
    }

    /** @return array{0: string, 1: string|null} The title and URL the model posts, or its type and key. */
    private static function describe(Model $model): array
    {
        if ($model instanceof ShareableOnFacebook) {
            try {
                $post = $model->toFacebookPost();

                return [$post->title(), $post->url()];
            } catch (Throwable) {
                // Fall through: the post already went out, the message still should.
            }
        }

        return [$model->getMorphClass().' #'.$model->getKey(), null];
    }
}
