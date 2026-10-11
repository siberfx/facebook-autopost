<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\FacebookAutopost\FacebookManager;

final class StatusCommand extends Command
{
    protected $signature = 'facebook:status';

    protected $description = 'Show the connected Facebook account and its token status';

    public function handle(FacebookManager $facebook): int
    {
        $connection = $facebook->connection();

        if (! $connection->connected) {
            $this->components->warn('Not connected to Facebook.');

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['Page', $connection->name ?? '—'],
            ['Page ID', $connection->pageId ?? '—'],
            ['Status', $connection->status ?? '—'],
            ['Connected', $connection->connectedAt?->toDateTimeString() ?? '—'],
            ['Expires', $connection->expiresAt?->toDateTimeString() ?? '—'],
            ['Days left', (string) ($connection->daysLeft() ?? '—')],
        ]);

        return self::SUCCESS;
    }
}
