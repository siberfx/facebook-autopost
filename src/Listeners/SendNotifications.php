<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Listeners;

use Siberfx\FacebookAutopost\Events\Shared;
use Siberfx\FacebookAutopost\Events\ShareFailed;
use Siberfx\FacebookAutopost\Events\TokenExpiringSoon;
use Siberfx\FacebookAutopost\Notifications\Message;
use Siberfx\FacebookAutopost\Notifications\Notifier;
use Throwable;

/** Turns the package events into Telegram/Slack messages when facebook-autopost.notifications says so. */
final class SendNotifications
{
    public function __construct(private readonly Notifier $notifier) {}

    public function handle(Shared|ShareFailed|TokenExpiringSoon $event): void
    {
        $key = match (true) {
            $event instanceof Shared => 'shared',
            $event instanceof ShareFailed => 'failed',
            $event instanceof TokenExpiringSoon => 'token_expiring',
        };

        if (! $this->notifier->wants($key)) {
            return;
        }

        try {
            $message = match (true) {
                $event instanceof Shared => Message::shared($event),
                $event instanceof ShareFailed => Message::failed($event),
                $event instanceof TokenExpiringSoon => Message::tokenExpiring($event),
            };
        } catch (Throwable $e) {
            report($e);

            return;
        }

        $this->notifier->notify($message);
    }
}
