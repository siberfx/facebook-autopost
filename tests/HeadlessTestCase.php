<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Tests;

abstract class HeadlessTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('facebook-autopost.ui.enabled', false);
    }
}
