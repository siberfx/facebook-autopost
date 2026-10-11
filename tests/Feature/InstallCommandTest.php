<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->envDir = sys_get_temp_dir().'/facebook-autopost-'.uniqid();
    File::ensureDirectoryExists($this->envDir);
    app()->useEnvironmentPath($this->envDir);
});

afterEach(function () {
    File::deleteDirectory($this->envDir);
    File::delete(config_path('facebook-autopost.php'));
    foreach (File::glob(database_path('migrations/*facebook_*_table.php')) as $migration) {
        File::delete($migration);
    }
});

it('publishes config and migrations and writes the theme to .env', function () {
    File::put($this->envDir.'/.env', "APP_NAME=Test\n");

    $this->artisan('facebook:install', ['--theme' => 'bootstrap'])
        ->expectsOutputToContain('FACEBOOK_UI_THEME=bootstrap')
        ->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toContain("FACEBOOK_UI_THEME=bootstrap\n")
        ->and(File::exists(config_path('facebook-autopost.php')))->toBeTrue()
        ->and(File::glob(database_path('migrations/*create_facebook_connections_table.php')))->not->toBeEmpty();
});

it('asks for the theme and prints the Tailwind source line', function () {
    File::put($this->envDir.'/.env', '');

    $this->artisan('facebook:install')
        ->expectsChoice('Which admin UI theme do you use?', 'tailwind', ['tailwind', 'bootstrap'])
        ->expectsOutputToContain("@source '../../vendor/siberfx/facebook-autopost/resources/views/tailwind'")
        ->assertSuccessful();
});

it('does not overwrite an existing theme key', function () {
    File::put($this->envDir.'/.env', "FACEBOOK_UI_THEME=tailwind\n");

    $this->artisan('facebook:install', ['--theme' => 'bootstrap'])->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toBe("FACEBOOK_UI_THEME=tailwind\n");
});

it('rejects an unknown theme', function () {
    $this->artisan('facebook:install', ['--theme' => 'bulma'])->assertFailed();
});

it('publishes the example model stub under its tag', function () {
    $target = base_path('stubs/facebook-autopost/ShareableModel.stub');
    File::delete($target);

    $this->artisan('vendor:publish', ['--tag' => 'facebook-autopost-stubs'])->assertSuccessful();

    expect(File::exists($target))->toBeTrue();

    File::delete($target);
    @rmdir(dirname($target));
});

it('prints the configured redirect URI and the gate to define', function () {
    config(['facebook-autopost.redirect_uri' => 'https://example.com/admin/facebook/callback']);
    File::put($this->envDir.'/.env', '');

    $this->artisan('facebook:install', ['--theme' => 'bootstrap'])
        ->expectsOutputToContain('Register https://example.com/admin/facebook/callback as an Authorized redirect URL')
        ->expectsOutputToContain("Gate::define('manage-facebook-autopost', fn (User \$user) => \$user->is_admin);")
        ->assertSuccessful();
});

it('prints the package callback URL when no redirect URI is configured', function () {
    File::put($this->envDir.'/.env', '');

    $this->artisan('facebook:install', ['--theme' => 'bootstrap'])
        ->expectsOutputToContain('Register http://localhost/facebook/callback as an Authorized redirect URL')
        ->assertSuccessful();
});

it('installs headless with --headless, skipping the theme', function () {
    File::put($this->envDir.'/.env', "APP_NAME=Test\n");

    $this->artisan('facebook:install', ['--headless' => true])
        ->expectsOutputToContain('Added FACEBOOK_UI=false to .env.')
        ->assertSuccessful();

    expect(File::get($this->envDir.'/.env'))->toBe("APP_NAME=Test\nFACEBOOK_UI=false\n");
});
