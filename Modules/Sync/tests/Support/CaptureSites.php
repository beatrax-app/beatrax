<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use Modules\Core\Public\Support\PatternScan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// Which tables something actually writes to the op log, read off the write
// sites themselves. Two Arch files ask that question — one from the merge
// registry, one from the live schema — and a global helper declared in either
// of them only exists in whichever paratest worker loaded that file, so the
// other file's check silently skipped in five of six CI runs. A class both
// autoload is the answer that does not depend on who else is in the process.
final class CaptureSites
{
    // The two producers that put a row on the wire: an EntityMutated dispatch,
    // and a direct OpLogWriter call from a capture listener. Both name their
    // table as the first named argument, so the match IS the write site.
    public const string PATTERN = "/(?:new EntityMutated\(|->write[A-Za-z]+\()\s*table:\s*'([a-z_]+)'/";

    /**
     * @return list<string>
     */
    public static function tables(): array
    {
        $found = [];

        foreach ([dirname(__DIR__, 2).'/Internal/Listeners', base_path('Modules')] as $dir) {
            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (str_contains($file->getPathname(), '/tests/')) {
                    continue;
                }

                $isListener = str_contains($file->getPathname(), '/Internal/Listeners/');
                $isDispatch = str_contains($source, 'new EntityMutated(');
                // Only callers count: the file defining captureRowsById() also names the
                // tables it EXCLUDES.
                $isBulk = str_contains($source, '->captureRowsById(');

                if (! $isListener && ! $isDispatch && ! $isBulk) {
                    continue;
                }

                // The table name has to be the argument of an actual write, not
                // merely present somewhere in a file that also happens to contain
                // one. A bare `table: '…'` anywhere marked the whole table captured,
                // which is how merchant_aliases passed this gate with a single YAML
                // insert covering for four uncaptured user-facing writes.
                $matches = PatternScan::all(self::PATTERN, $source);

                foreach ($matches[1] as $table) {
                    $found[$table] = true;
                }

                if (! $isBulk) {
                    continue;
                }

                // Only a list the file actually walks: counting every const array meant a
                // table struck out of the capture loop still read as captured.
                $lists = PatternScan::sets("/const (?:array\\s+)?([A-Z_]+) = \[([^\]]*)\];/", $source);

                foreach ($lists as $list) {
                    if (! str_contains($source, 'foreach (self::'.$list[1])) {
                        continue;
                    }

                    $bulk = PatternScan::all("/'([a-z_]{3,})'/", $list[2]);

                    foreach ($bulk[1] as $table) {
                        $found[$table] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }
}
