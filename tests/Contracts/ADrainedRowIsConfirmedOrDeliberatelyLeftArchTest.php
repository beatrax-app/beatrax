<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

/**
 * @link ../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */

/** @return list<string> repo-relative files that call RelayClient::drain() */
function drainedRowRelayReaders(): array
{
    $readers = [];

    foreach (BackendSourceFiles::all() as $path) {
        $source = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? $source;

        if (! PatternScan::matches('/\bRelayClient\b/', $stripped)) {
            continue;
        }

        if (PatternScan::matches('/->drain\(/', $stripped)) {
            $readers[] = str_replace(base_path().'/', '', $path);
        }
    }

    return $readers;
}

/** @return list<string> the subset of those readers that never confirm a row away */
function drainedRowReadersThatNeverConfirm(): array
{
    $offenders = [];

    foreach (drainedRowRelayReaders() as $relative) {
        $source = (string) file_get_contents(base_path($relative));
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? $source;

        if (! PatternScan::matches('/->confirm\(/', $stripped)) {
            $offenders[] = $relative;
        }
    }

    return $offenders;
}

// A relay drain is not destructive: the blob stays in the recipient's mailbox
// until a confirm deletes it. A reader that never confirms re-downloads the
// whole mailbox on every tick until the TTL retires it, and — because the drain
// call itself succeeded — reports the leg as reached while nothing was applied.
// Both halves of that were live on the phone's off-LAN leg.
it('confirms rows away in every class that drains a relay mailbox', function (): void {
    expect(drainedRowRelayReaders())->not->toBe(
        [],
        'The scan found no RelayClient::drain() call at all, which means it stopped reading the tree rather than finding it clean.',
    );

    expect(drainedRowReadersThatNeverConfirm())->toBe(
        [],
        "A class that drains a relay mailbox and never calls confirm() leaves every blob standing until the TTL,\n".
        "and re-downloads all of them on the next tick. Confirm what this leg is TERMINALLY the reader of and\n".
        "leave another protocol's frames for their own poll — see PairingFrameCourier::applyDrainedRow().\n  ".
        implode("\n  ", drainedRowReadersThatNeverConfirm()),
    );
});
