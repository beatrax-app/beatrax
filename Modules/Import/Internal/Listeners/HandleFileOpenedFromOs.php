<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Listeners;

use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;
use Modules\Desktop\Public\Events\FileOpenedFromOs;

/**
 * Routes a .csv `FileOpenedFromOs` intent into the Import flow.
 *
 * SC3 routing caveat (locked): `.csv` files routed by the OS double-click
 * land in the user-facing import flow — `Modules\Import` — not the
 * `Modules\Ingestion` parser surface. This listener subscribes to the
 * Public `FileOpenedFromOs` event, filters to the `csv` extension, and
 * persists the validated path into the Desktop module's session-scoped
 * pending-intent store. The user then lands on the Desktop staging
 * page (`route('desktop.file-staging')`), where "Start import" enters
 * `imports.new` / `imports.preview`.
 *
 * Constructor DI: the cross-module dependency is the Public
 * `RemembersPendingFileIntent` contract — the listener never reaches
 * into `Modules\Desktop\Internal\*` directly.
 *
 * Idempotency: a re-fired `FileOpenedFromOs` (cold start followed by a
 * race-condition second-instance event for the same file) overwrites
 * the intent with the same payload — the store keeps at most one
 * pending intent per session (single-window UX).
 */
final class HandleFileOpenedFromOs
{
    public function __construct(
        private readonly RemembersPendingFileIntent $intent,
    ) {}

    public function handle(FileOpenedFromOs $event): void
    {
        if ($event->extension !== 'csv') {
            return;
        }
        $this->intent->remember($event->path, $event->extension);
    }
}
