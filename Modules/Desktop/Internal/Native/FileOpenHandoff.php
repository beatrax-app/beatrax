<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Modules\Core\Public\Contracts\Clock;
use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;

// The write half of a file opened from the OS, and deliberately a class of its
// own: it is reached from a shell event, so it must be able to reach no session
// at all. Sharing one class with the reader is what let the session write hide
// behind a method the shell never calls.
final readonly class FileOpenHandoff implements RemembersPendingFileIntent
{
    public function __construct(
        private ShellHandoff $handoff,
        private Clock $clock,
    ) {}

    public function remember(string $path, string $extension): void
    {
        if (! in_array($extension, FileOpenIntake::SUPPORTED_EXTENSIONS, true)) {
            return;
        }

        $this->handoff->leave(ShellHandoff::FILE_INTENT, [
            'path' => $path,
            'extension' => $extension,
            'remembered_at' => $this->clock->now()->getTimestamp(),
        ]);
    }
}
