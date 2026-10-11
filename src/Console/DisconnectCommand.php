<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Console;

use Illuminate\Console\Command;
use Siberfx\FacebookAutopost\FacebookManager;

final class DisconnectCommand extends Command
{
    protected $signature = 'facebook:disconnect {--force : Do not ask for confirmation}';

    protected $description = 'Revoke and forget the Facebook connection';

    public function handle(FacebookManager $facebook): int
    {
        if (! $facebook->isConnected()) {
            $this->components->info('Not connected to Facebook.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Disconnect Facebook and revoke the token?')) {
            return self::FAILURE;
        }

        $revoked = $facebook->disconnect();

        $revoked
            ? $this->components->info('Disconnected. Facebook revoked the token.')
            : $this->components->warn('Disconnected here, but Facebook did not confirm revoking the token.');

        return self::SUCCESS;
    }
}
