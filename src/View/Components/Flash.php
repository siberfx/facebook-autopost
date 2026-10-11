<?php

declare(strict_types=1);

namespace Siberfx\FacebookAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Siberfx\FacebookAutopost\Support\StatusMessages;
use Siberfx\FacebookAutopost\Support\Theme;

final class Flash extends Component
{
    public function render(): View
    {
        return view(Theme::view('flash'), ['flash' => StatusMessages::current(request())]);
    }
}
