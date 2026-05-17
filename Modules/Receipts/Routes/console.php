<?php

declare(strict_types=1);

/*
 * Module-local artisan command bindings for the Receipts module.
 *
 * Intentionally empty: the per-user hourly ProcessFetchedInboxMessagesJob
 * schedule lives in the root `routes/console.php` alongside the
 * EmailScan + Chains schedule entries (one closure with DatabaseManager
 * + Bus Dispatcher DI, plus the .name() / .hourly() /
 * .withoutOverlapping(...) chain).
 *
 * The watched-folder secondary path (ScanInboxDropFolderJob) is
 * scheduled there as well; the binding lives in the root console
 * routes so a single file enumerates every per-user schedule entry.
 */
