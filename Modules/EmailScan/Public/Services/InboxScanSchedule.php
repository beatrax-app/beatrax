<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use Modules\Core\Public\Scheduling\MobileBackgroundSchedule;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md#a-device-that-schedules-no-scan-cannot-be-behind-one
 */
final class InboxScanSchedule
{
    // routes/console.php names the hourly closure, and it is the only thing
    // that moves last_scan_at without a reader tapping Scan now.
    private const string INCREMENTAL_SCHEDULE = 'email-scan.incremental';

    // Asked of desktopOnly() rather than of the platform alone: a phone that
    // ever gains the scan retires this by moving one line, and until then the
    // absence is explained in the one place that explains every other one.
    public static function runsOnThisDevice(): bool
    {
        if (UserDataPathService::platform() === null) {
            return true;
        }

        return ! array_key_exists(self::INCREMENTAL_SCHEDULE, MobileBackgroundSchedule::desktopOnly());
    }
}
