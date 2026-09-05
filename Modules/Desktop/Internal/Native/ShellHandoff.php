<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

/**
 * @link ../../../../.docs/features/desktop/architecture.md#a-native-event-never-holds-the-windows-session
 */
// The shell's own process cannot write the window's session: notifyLaravel()
// posts from Electron's main process with no cookie jar, onto a route loaded
// without StartSession. A native event leaves the bare fact here instead, and
// the window's next request claims it against the session it really holds.
final readonly class ShellHandoff
{
    public const string LOCK_DEMANDED = 'desktop.shell-handoff.lock-demanded';

    public const string FILE_INTENT = 'desktop.shell-handoff.file-intent';

    public function __construct(
        private ShellState $state,
    ) {}

    // Forever, deliberately: a lock demanded by a window that closed has to be
    // still waiting whenever the app is next opened, however long that takes.
    /**
     * @param  array<string, scalar>  $fact
     */
    public function leave(string $slot, array $fact = []): void
    {
        $this->state->write($slot, $fact);
    }

    // Reading spends it. A fact claimed twice would lock a reader who had
    // already answered the PIN, or stage the same document again.
    /**
     * @return array<array-key, mixed>|null
     */
    public function take(string $slot): ?array
    {
        $fact = $this->state->read($slot);

        if ($fact !== null) {
            $this->state->forget($slot);
        }

        return $fact;
    }
}
