<?php

declare(strict_types=1);

it('merges the package config with safe defaults', function () {
    expect(config('facebook-autopost.autopost.enabled'))->toBeFalse()
        ->and(config('facebook-autopost.autopost.in_console'))->toBeFalse()
        ->and(config('facebook-autopost.api_version'))->toBe('v25.0')
        ->and(config('facebook-autopost.routes.prefix'))->toBe('facebook');
});

it('creates the package tables', function () {
    expect(Schema::hasTable('facebook_connections'))->toBeTrue()
        ->and(Schema::hasTable('facebook_posts'))->toBeTrue();
});
