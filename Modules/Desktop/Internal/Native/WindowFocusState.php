<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

/**
 * @link ../../../../.docs/features/desktop/architecture.md#state-a-shell-event-writes-does-not-outlive-its-request
 */
// Written by a shell event, read by requests the shell never touches, so it
// cannot be held on the object: those are separate PHP processes. Absent means
// focused — a fresh launch opens in front of the user, and treating that as
// unfocused pops an OS toast on top of the in-app banner it duplicates.
final readonly class WindowFocusState
{
    public const string SLOT = 'desktop.shell-state.window-focused';

    private const string FIELD = 'focused';

    public function __construct(
        private ShellState $state,
    ) {}

    public function isFocused(): bool
    {
        $fact = $this->state->read(self::SLOT);

        return (bool) ($fact[self::FIELD] ?? true);
    }

    public function markFocused(): void
    {
        $this->record(true);
    }

    public function markBlurred(): void
    {
        $this->record(false);
    }

    // A launch starts from the default again. The fact now survives a quit, and
    // a shell killed while blurred would otherwise leave `false` on disk for a
    // window that opens in front of the reader.
    public function forgetAcrossLaunch(): void
    {
        $this->state->forget(self::SLOT);
    }

    private function record(bool $focused): void
    {
        $this->state->write(self::SLOT, [self::FIELD => $focused]);
    }
}
