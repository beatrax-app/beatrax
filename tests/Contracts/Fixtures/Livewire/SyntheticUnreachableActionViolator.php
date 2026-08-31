<?php

declare(strict_types=1);

namespace Tests\Contracts\Fixtures\Livewire;

use Livewire\Component;

// Deliberately broken: `wipeEverythingSyntheticallyUnreachable` is public, so a
// crafted /livewire/update payload can call it, and nothing names it anywhere.
// EveryWireCallableMethodIsReachableFromTheUiArchTest's inverse pass fails if
// the scan stops flagging it, so do not "fix" this component.
final class SyntheticUnreachableActionViolator extends Component
{
    public function wipeEverythingSyntheticallyUnreachable(): void {}

    public function render(): string
    {
        return '<div></div>';
    }
}
