<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Modules\Desktop\Internal\Native\FileOpenIntake;
use Native\Desktop\Events\App\OpenFile;

/**
 * Native-event bridge: NativePHP's `\Native\Desktop\Events\App\OpenFile`
 * → `FileOpenIntake` (the validation boundary) → Public
 * `FileOpenedFromOs`.
 *
 * NativePHP's electron-plugin (vendor/nativephp/desktop/...) wires
 * macOS's `app.on('open-file')` event to a Laravel event that fires
 * inside the bundled PHP server (via `notifyLaravel('events', ...)`
 * — see `Modules/Desktop/Internal/Native/FileAssociationSpike.md`).
 *
 * The published Electron project's `src/main/index.js` (also patched
 * by the spike) extends this transport to Windows / Linux:
 *
 *   - cold-start `process.argv` parsing,
 *   - `app.on('second-instance')` argv parsing,
 *
 * both POST the same Laravel event with the file path payload. Every
 * cross-OS path therefore converges on this one listener, which hands
 * the path to `FileOpenIntake` — the security boundary — without
 * doing any validation itself.
 *
 * The `OpenFile` event's payload is documented as the constructor's
 * `$path` property. Some NativePHP versions deliver it as a string, a
 * single-element array, or an associative payload; the listener
 * normalises down to a string before invoking the intake.
 */
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

    /**
     * NativePHP's event delivers `path` as an untyped property; defensive
     * normalization keeps the bridge tolerant of payload-shape drift
     * between NativePHP versions without weakening downstream validation.
     */
    private function normalize(mixed $raw): ?string
    {
        if (is_string($raw)) {
            return $raw;
        }
        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }
}
