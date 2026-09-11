<?php

declare(strict_types=1);

namespace Tests\Contracts\Fixtures\Livewire;

use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

// The pair WireCallableMethods has to tell apart. Measured against Livewire
// itself: `calls[{method:"onSomethingDispatched"}]` runs the listener with the
// payload's own arguments, while the same entry naming `derivedTotal` is
// refused with CannotCallComputedDirectlyException.
final class SyntheticEventListenerEndpoint extends Component
{
    public string $serverChosenKey = '';

    #[On('synthetic-probe')]
    public function onSomethingDispatched(string $key = ''): void
    {
        $this->serverChosenKey = $key;
    }

    #[Computed]
    public function derivedTotal(): int
    {
        return 1;
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
