<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Desktop\Public\Events\FileOpenedFromOs;

// The one validation boundary every OS-supplied file path converges on. The
// path is only ever read, never handed to exec()/shell_exec().
final class FileOpenIntake
{
    // Lower-cased, no leading dot — matches the macOS CFBundleDocumentTypes and
    // Linux MimeType= entries the published Electron project registers.
    public const SUPPORTED_EXTENSIONS = ['csv', 'eml'];

    /**
     * @var array<string, int>
     */
    public const MAX_BYTES = [
        'csv' => 50 * 1024 * 1024, // 50 MB — large bank exports
        'eml' => 5 * 1024 * 1024,  // 5 MB — a fat receipt with inline images
    ];

    public function __construct(
        private readonly Dispatcher $events,
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
