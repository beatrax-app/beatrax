<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

// Defaults to focused: a fresh launch opens in front of the user, so treating it
// as unfocused until the first WindowBlurred event popped a redundant OS toast on
// top of the in-app banner.
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
