<?php

declare(strict_types=1);

// Intentionally empty: the per-user hourly ProcessFetchedInboxMessagesJob
// schedule (and the watched-folder ScanInboxDropFolderJob) live in the
// root routes/console.php alongside the EmailScan + Chains schedule
// entries, so one file enumerates every per-user schedule.
