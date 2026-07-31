<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\FileOpenIntake;
use Native\Desktop\Events\App\OpenFile;

// Bridges NativePHP's macOS app.on('open-file') event (and the
// cross-OS argv / second-instance paths the published Electron main.js
// extends to Windows/Linux) to FileOpenIntake — the one validation
// boundary every OS-file-open path converges on.
final class HandleNativeOpenFile
{
    public function __construct(
        private readonly FileOpenIntake $intake,
    ) {}

    public function handle(OpenFile $event): void
    {
        $path = $this->normalize($event->path);
        if ($path === null) {
            return;
        }

        $this->intake->receive($path);
    }

    // NativePHP's event delivers `path` as an untyped property: some
    // versions send a plain string, others a keyed or list-shaped
    // array. A string passes through; an array is resolved key-first.
    private function normalize(mixed $raw): ?string
    {
        return match (true) {
            is_string($raw) => $raw,
            is_array($raw) => $this->pathFromArray($raw),
            default => null,
        };
    }

    // A key-aware lookup takes the explicit `path` entry first so a
    // sibling metadata string (e.g. 'open-file') is never picked over
    // the real path; only then does it fall back to list iteration.
    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function pathFromArray(array $raw): ?string
    {
        $keyed = $raw['path'] ?? null;
        if (is_string($keyed) && $keyed !== '') {
            return $keyed;
        }

        return $this->firstListString($raw);
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function firstListString(array $raw): ?string
    {
        foreach ($raw as $key => $value) {
            if (is_int($key) && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
