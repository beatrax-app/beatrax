<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

/**
 * Shared helper for locating a system binary in standard paths (IN-03).
 *
 * Extracted from the verbatim-duplicated findBinary() implementations in
 * MdnsAdvertiser and MdnsBrowser. Both shell out to dns-sd / avahi CLIs and need
 * to resolve their absolute paths the same way.
 *
 * @internal Discovery namespace only.
 */
trait LocatesSystemBinary
{
    /**
     * Look up a binary by name in standard system paths.
     *
     * Returns the full path if found and executable, null otherwise.
     */
    private function findBinary(string $name): ?string
    {
        $paths = ['/usr/bin', '/usr/local/bin', '/bin', '/usr/sbin'];
        foreach ($paths as $dir) {
            $full = $dir.DIRECTORY_SEPARATOR.$name;
            if (is_file($full) && is_executable($full)) {
                return $full;
            }
        }

        return null;
    }
}
