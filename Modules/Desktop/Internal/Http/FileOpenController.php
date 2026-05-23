<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Desktop\Internal\Native\FileOpenIntake;

/**
 * HTTP entry point for an OS file-open intent that arrives via the
 * Electron main process.
 *
 * The published Electron project (`nativephp/electron/src/main/index.js`)
 * POSTs the file path to this route when:
 *
 *   - cold-start argv parsing finds a `.csv` / `.eml` path (Windows / Linux),
 *   - `app.on('second-instance')` fires with such a path on the
 *     running instance.
 *
 * The macOS `app.on('open-file')` path is handled by NativePHP's own
 * plugin, which fires a Laravel `\Native\Desktop\Events\App\OpenFile`
 * event — `HandleNativeOpenFile` subscribes and feeds the same
 * `FileOpenIntake`. Both paths converge on one validation boundary.
 *
 * Security:
 *
 *   - The route sits behind `['web']` only — NOT `auth`. A file-open
 *     received while the user is logged out must still reach the
 *     intake so the pending-intent flow can save it across the login
 *     round-trip (D-04). The intake itself rejects malicious / unknown
 *     paths before any work is done.
 *   - CSRF protection from the `web` middleware group protects the
 *     route from a forged cross-origin POST (T-15-11). The Electron
 *     main process is the only legitimate caller and runs in-process
 *     against the loopback PHP server.
 *   - The controller does NOT execute the path, read it directly, or
 *     pass it to a shell. It hands the string to `FileOpenIntake`,
 *     which canonicalises, allow-lists, size-limits, and emits — or
 *     rejects silently.
 *
 * Constructor DI: the intake collaborator is injected; no facade calls.
 */
final class FileOpenController
{
    public function __construct(
        private readonly FileOpenIntake $intake,
    ) {}

    public function __invoke(Request $request): Response
    {
        $path = $request->input('path');
        if (! is_string($path) || $path === '') {
            // Malformed payload — accept the HTTP request but emit no
            // event. The Electron caller is best-effort; a 200 keeps
            // it from retrying needlessly.
            return new Response('', 204);
        }

        $this->intake->receive($path);

        return new Response('', 204);
    }
}
