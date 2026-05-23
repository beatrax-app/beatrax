<?php

declare(strict_types=1);

namespace Modules\Desktop\Public\Contracts;

/**
 * Cross-module seam exposing the pending-file-intent store (D-04).
 *
 * The Receipts and Import module listeners that subscribe to
 * `FileOpenedFromOs` need to persist the validated path into the
 * Desktop module's session-scoped intent store so the user lands on
 * the staging page (PRESENT state) rather than the empty state.
 *
 * The concrete implementation (`PendingFileIntent`) is internal to the
 * Desktop module — keeping its session-store collaborator and the
 * stale-path check out of the listeners. The listeners depend only on
 * this contract + the Public `FileOpenedFromOs` event; neither reaches
 * into `Modules\Desktop\Internal\*` directly.
 */
interface RemembersPendingFileIntent
{
    /**
     * Persists a validated file-open intent under the current session.
     *
     * @param  string  $path       Canonicalised, allow-listed file path.
     * @param  string  $extension  'csv' | 'eml' — the file extension
     *                             without the leading dot.
     */
    public function remember(string $path, string $extension): void;
}
