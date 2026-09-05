<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

// Stands in for the next action on an auth-free route that forgets to repeat
// mount()'s check: the seam behind the guard has to be provable without waiting
// for a second real one to ship.
final class ReaderlessCallProbe extends Component
{
    public function boom(CurrentUser $currentUser): void
    {
        $currentUser->user();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
