<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Listeners;

use Modules\Desktop\Public\Contracts\RemembersPendingFileIntent;
use Modules\Desktop\Public\Events\FileOpenedFromOs;

/**
 * Routes a .eml `FileOpenedFromOs` intent into the Receipts flow (D-02).
 *
 * `.eml` files routed by the OS double-click land in the Receipts
 * pipeline — the validated `.eml` ingestion path that `FileDropEmlBlobStore`
 * owns. This listener subscribes to the Public `FileOpenedFromOs`
 * event, filters to the `eml` extension, and persists the validated
 * path into the Desktop module's session-scoped pending-intent store.
 * The user then lands on the Desktop staging page
 * (`route('desktop.file-staging')`), where "Start import" enters the
 * email-file step of the user-facing upload wizard (the same wizard
 * the dashboard "Import file…" CTA opens — `imports.new`).
 *
 * Constructor DI: the cross-module dependency is the Public
 * `RemembersPendingFileIntent` contract — the listener never reaches
 * into `Modules\Desktop\Internal\*` directly.
 *
 * Idempotency: a re-fired `FileOpenedFromOs` (cold start followed by a
 * race-condition second-instance event for the same file) overwrites
 * the intent with the same payload — the store keeps at most one
 * pending intent per session.
 */
final class HandleFileOpenedFromOs
{
    public function __construct(
        private readonly RemembersPendingFileIntent $intent,
    ) {}

    public function handle(FileOpenedFromOs $event): void
    {
        if ($event->extension !== 'eml') {
            return;
        }
        $this->intent->remember($event->path, $event->extension);
    }
}
