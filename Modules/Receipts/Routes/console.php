<?php

declare(strict_types=1);

/*
 * Module-local artisan command bindings for the Receipts module.
 *
 * Intentionally empty in Wave 1 — the per-user hourly
 * `ProcessFetchedInboxMessagesJob` schedule lives in the root
 * `routes/console.php` to mirror the EmailScan + Chains schedule
 * entries (one closure with DatabaseManager + Bus Dispatcher DI,
 * plus the .name() / .hourly() / .withoutOverlapping(...) chain).
 *
 * A future watched-folder schedule for the file-drop secondary
 * path will land here in a later plan.
 */
