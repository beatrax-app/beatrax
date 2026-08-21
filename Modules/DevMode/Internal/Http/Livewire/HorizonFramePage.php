<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('dev::layouts.dev-shell')]
final class HorizonFramePage extends Component
{
    public function render(ViewFactory $views): View
    {
        return $views->make('dev::livewire.horizon-frame-page');
    }
}
