<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Siberfx\FacebookAutopost\Tests\RoutesDisabledTestCase;

uses(RoutesDisabledTestCase::class);

it('registers no routes when disabled', function () {
    expect(app('router')->has('facebook-autopost.callback'))->toBeFalse()
        ->and(app('router')->has('facebook-autopost.share'))->toBeFalse();
});

it('installs without the callback route and says to set the redirect URI', function () {
    $envDir = sys_get_temp_dir().'/facebook-autopost-'.uniqid();
    File::ensureDirectoryExists($envDir);
    File::put($envDir.'/.env', '');
    app()->useEnvironmentPath($envDir);

    try {
        $this->artisan('facebook:install', ['--theme' => 'tailwind'])
            ->expectsOutputToContain('Set FACEBOOK_REDIRECT_URI to the URL you registered')
            ->assertSuccessful();
    } finally {
        File::deleteDirectory($envDir);
        File::delete(config_path('facebook-autopost.php'));
        foreach (File::glob(database_path('migrations/*facebook_*_table.php')) as $migration) {
            File::delete($migration);
        }
    }
});
