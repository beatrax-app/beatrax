<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Desktop\Public\Events\FileOpenedFromOs;

// The one validation boundary every OS-supplied file path converges on. The
// path is only ever read, never handed to exec()/shell_exec().
final readonly class FileOpenIntake
{
    // Lower-cased, no leading dot. The document types the OS is told about are
    // declared by scripts/nativephp_inject_file_associations.php, and the two
    // lists are pinned equal: an extension the shell routes here that this
    // refuses is a double-click that opens the app and does nothing.
    public const array SUPPORTED_EXTENSIONS = ['csv', 'eml'];

    /**
     * @var array<string, int>
     */
    public const array MAX_BYTES = [
        'csv' => 50 * 1024 * 1024, // 50 MB — large bank exports
        'eml' => 5 * 1024 * 1024,  // 5 MB — a fat receipt with inline images
    ];

    public function __construct(
        private Dispatcher $events,
    ) {}

    public function receive(string $path): void
    {
        $accepted = $this->accept($path);
        if ($accepted === null) {
            return;
        }

        $this->events->dispatch(new FileOpenedFromOs(
            path: $accepted['path'],
            extension: $accepted['extension'],
        ));
    }

    /**
     * @return array{path: string, extension: string}|null
     */
    private function accept(string $path): ?array
    {
        $canonical = realpath($path);
        if ($canonical === false || ! is_file($canonical)) {
            return null;
        }

        $extension = strtolower(pathinfo($canonical, PATHINFO_EXTENSION));

        return $this->withinCap($canonical, $extension)
            ? ['path' => $canonical, 'extension' => $extension]
            : null;
    }

    private function withinCap(string $canonical, string $extension): bool
    {
        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return false;
        }

        $size = @filesize($canonical);

        return $size !== false && $size <= self::MAX_BYTES[$extension];
    }
}
