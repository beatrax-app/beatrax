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
     *
     * The path resolution order is intentional:
     *
     *   1. A string payload is returned verbatim — the canonical shape.
     *   2. An array payload is searched for a `path` key first. macOS
     *      open-file payloads and a future structured Electron payload
     *      (e.g. `{'type': 'open-file', 'path': '/Users/.../x.csv'}`)
     *      both carry the path on a named field. Iterating the array
     *      without a key-aware lookup would pick the first non-empty
     *      string — `'open-file'` in the example above — and downstream
     *      `realpath()` would fail closed, silently dropping a
     *      legitimate file-open.
     *   3. A list-shaped payload (e.g. `[0 => '/Users/.../x.csv']`) is
     *      handled by the iteration fallback so single-element-array
     *      shapes some older NativePHP versions emit still resolve.
     */
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
                    // Already inspected above; a non-string / empty
                    // value here means the field exists but is unusable
                    // — skip so the iteration does not pick a sibling
                    // string off the same payload.
                    continue;
                }
                if (! is_int($key)) {
                    // Reject other named fields — they carry NativePHP
                    // event metadata (event type, timestamp, etc.),
                    // not the file path. The iteration fallback exists
                    // for list-shaped payloads only.
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
