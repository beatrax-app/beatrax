<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

trait LocatesSystemBinary
{
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
