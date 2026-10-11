<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\Support;

final class Ui
{
    /** False in headless mode: no views or components, JSON-only responses. */
    public static function enabled(): bool
    {
        return (bool) config('facebook-autopost.ui.enabled', true);
    }
}
