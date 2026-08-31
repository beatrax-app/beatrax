<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\FileOpenIntake;
use Native\Desktop\Events\App\OpenFile;

// Bridges NativePHP's macOS app.on('open-file') event — and the argv /
// second-instance paths the published Electron main.js extends to Windows and
// Linux — to FileOpenIntake.
final readonly class HandleNativeOpenFile
{
    public function __construct(
        private FileOpenIntake $intake,
    ) {}

    public function handle(OpenFile $event): void
    {
        $path = $this->normalize($event->path);
        if ($path === null) {
            return;
        }

        $this->intake->receive($path);
    }

    // NativePHP's event delivers `path` as an untyped property: some versions
    // send a plain string, others a keyed or list-shaped array.
    private function normalize(mixed $raw): ?string
    {
        return match (true) {
            is_string($raw) => $raw,
            is_array($raw) => $this->pathFromArray($raw),
            default => null,
        };
    }

    // The explicit `path` entry wins so a sibling metadata string (e.g.
    // 'open-file') is never picked over the real path.
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
