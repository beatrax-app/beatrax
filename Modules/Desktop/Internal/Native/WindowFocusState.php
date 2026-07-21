<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

// Tracks OS focus for the beatrax main window. Defaults to focused: a
// fresh launch opens the window directly in front of the user, so
// treating it as unfocused until the first WindowBlurred event would
// pop a redundant OS toast on top of the in-app banner.
final class WindowFocusState
{
    private bool $focused = true;

    public function isFocused(): bool
    {
        return $this->focused;
    }

    public function markFocused(): void
    {
        $this->focused = true;
    }

    public function markBlurred(): void
    {
        $this->focused = false;
    }
}
