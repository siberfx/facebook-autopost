<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\FacebookAutopost\Events\TokenExpiringSoon;
use Siberfx\FacebookAutopost\FacebookManager;

final class CheckTokenCommand extends Command
{
    protected $signature = 'facebook:check-token {--days= : Warn when fewer days remain (default from config)}';

    protected $description = 'Fire TokenExpiringSoon when the Facebook token is about to expire';

    public function handle(FacebookManager $facebook): int
    {
        $connection = $facebook->connection();

        if (! $connection->connected) {
            $this->components->info('Not connected to Facebook.');

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?? config('facebook-autopost.expiry_warning_days', 7));
        $left = $connection->daysLeft();

        if ($left === null && ! in_array($connection->status, ['expired', 'revoked'], true)) {
            $this->components->info('The token expiry is unknown.');

            return self::SUCCESS;
        }

        $left ??= 0;

        if ($left <= $days || in_array($connection->status, ['expired', 'revoked'], true)) {
            event(new TokenExpiringSoon($connection, $left));
            $this->components->warn("The Facebook token expires in {$left} days. Connect again to renew it.");

            return self::SUCCESS;
        }

        $this->components->info("The Facebook token is valid for {$left} more days.");

        return self::SUCCESS;
    }
}
