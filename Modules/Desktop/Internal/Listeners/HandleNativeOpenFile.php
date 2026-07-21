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
        $raw = $event->path;
        $path = $this->normalize($raw);
        if ($path === null) {
            return;
        }
        $this->intake->receive($path);
    }

    // NativePHP's event delivers `path` as an untyped property; some
    // versions send a plain string, others a keyed or list-shaped
    // array. A key-aware lookup avoids picking a sibling metadata
    // string (e.g. 'open-file') over the real path.
    private function normalize(mixed $raw): ?string
    {
        if (is_string($raw)) {
            return $raw;
        }
        if (is_array($raw)) {
            $pathField = $raw['path'] ?? null;
            if (is_string($pathField) && $pathField !== '') {
                return $pathField;
            }

            foreach ($raw as $key => $value) {
                if ($key === 'path') {
                    continue;
                }
                if (! is_int($key)) {
                    continue;
                }
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
