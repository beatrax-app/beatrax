<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Desktop\Public\Events\FileOpenedFromOs;

/**
 * Security boundary for the OS-supplied file-open intent (PKG-06 / D-01 / D-02).
 *
 * The OS hands diederik an arbitrary, attacker-influenceable file path
 * (T-15-09 in the plan threat register). `FileOpenIntake` is the ONE
 * place that path is validated; only a path that passes every check
 * emits the Public `FileOpenedFromOs` event for downstream consumers
 * (Import / Receipts listeners).
 *
 * Validation rules (defence-in-depth — the Electron-side
 * `extractSupportedFilePath` helper applies an analogous filter, but
 * the PHP boundary is authoritative):
 *
 *   1. The path canonicalises via `realpath()`. A path with traversal
 *      segments that does not resolve to an existing file (`..` over
 *      the filesystem root, a flash drive that was unmounted, etc.) is
 *      rejected — `realpath()` returns `false` on those inputs and the
 *      intake exits without emitting.
 *   2. The canonical path must be a file (not a directory and not a
 *      special device entry).
 *   3. The extension must be EXACTLY `.csv` or `.eml`, case-insensitive.
 *      Anything else — `.exe`, `.sh`, no extension at all — is
 *      rejected.
 *   4. The file is size-limited (MAX_BYTES) before any downstream
 *      consumer reads it; the pipelines do their own validation, but
 *      a bounded prefilter at the boundary avoids spending CPU on a
 *      multi-GB payload that the OS pointed at us by mistake.
 *   5. The path is NEVER `exec()`'d, `shell_exec()`'d, or otherwise
 *      handed to the OS shell. The intake emits the event and stops.
 *
 * `FileOpenedFromOs` is kept a dumb DTO — the validation lives here, not
 * in the event constructor. Downstream listeners trust the path is a
 * resolved, allow-listed `.csv` / `.eml` file inside the local
 * filesystem.
 */
final class FileOpenIntake
{
    /**
     * Lower-cased extension allow-list, normalised without the leading
     * dot. Matches the macOS `CFBundleDocumentTypes` and Linux
     * `MimeType=` entries the published Electron project registers.
     */
    public const SUPPORTED_EXTENSIONS = ['csv', 'eml'];

    /**
     * Per-extension soft caps on the file size accepted at the
     * boundary. Bank / PayPal CSVs and `.eml` receipts have very
     * different realistic sizes — a year of ASN CSV exports tops out
     * around a few MB; a single `.eml` receipt is realistically well
     * under 1 MB. Tight per-extension caps reject accidental misdrops
     * (a multi-GB log file mis-dragged onto the dock, a corrupted
     * mailbox file) before the downstream pipelines spend CPU on
     * something the user did not mean to import.
     *
     * @var array<string, int>
     */
    public const MAX_BYTES = [
        'csv' => 50 * 1024 * 1024, // 50 MB — large bank exports
        'eml' => 5 * 1024 * 1024,  // 5 MB — a fat receipt with inline images
    ];

    public function __construct(
        private readonly Dispatcher $events,
    ) {}

    /**
     * Validates the OS-supplied path and emits `FileOpenedFromOs` when
     * every check passes. Returns silently on rejection — the caller
     * (the `FileOpenController` HTTP route, the
     * `HandleNativeOpenFile` listener) does not need a result; the
     * absence of an event IS the rejection signal.
     */
    public function receive(string $path): void
    {
        $canonical = realpath($path);
        if ($canonical === false) {
            return;
        }

        if (! is_file($canonical)) {
            return;
        }

        $extension = strtolower(pathinfo($canonical, PATHINFO_EXTENSION));
        if (! in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return;
        }

        // `$extension` is already constrained to SUPPORTED_EXTENSIONS
        // by the in_array() guard above, and every supported extension
        // has a matching MAX_BYTES entry — Larastan can prove the
        // direct lookup is total, so no `??` fallback is needed.
        $size = @filesize($canonical);
        $cap = self::MAX_BYTES[$extension];
        if ($size === false || $size > $cap) {
            return;
        }

        $this->events->dispatch(new FileOpenedFromOs(
            path: $canonical,
            extension: $extension,
        ));
    }
}
