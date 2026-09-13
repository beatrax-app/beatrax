<?php

declare(strict_types=1);

namespace Modules\Desktop\Commands;

use Illuminate\Console\Command;
use Modules\Desktop\Internal\Boot\ShippedDesktopContents;

final class InspectDesktopBundleCommand extends Command
{
    /** @var string */
    protected $signature = 'desktop:inspect-bundle {path : the built application tree to read — a .app, or electron-builder\'s unpacked directory}';

    /** @var string */
    protected $description = 'Refuse a built desktop tree that carries key material, a secret, a database or a vendored package\'s dev tooling.';

    public function handle(ShippedDesktopContents $contents): int
    {
        /** @var string $path */
        $path = $this->argument('path');

        // A path that is not there is not a tree that passed: the check exists
        // to be run on a build, and reporting success for a missing directory
        // is how a renamed output turns into a green release.
        if (! is_dir($path)) {
            $this->components->error('desktop:inspect-bundle: no bundle at '.$path);

            return self::FAILURE;
        }

        $files = $contents->fileCount($path);

        // Nothing read is not nothing found. A walk over an empty directory
        // reports exactly like a walk over a clean bundle, and that silence is
        // what let an unread artifact pass for a read one.
        if ($files === 0) {
            $this->components->error('desktop:inspect-bundle: found no file at all in '.$path);

            return self::FAILURE;
        }

        return $this->report($contents->refusals($path), $files, $path);
    }

    /** @param list<string> $refusals */
    private function report(array $refusals, int $files, string $path): int
    {
        if ($refusals !== []) {
            foreach ($refusals as $refusal) {
                $this->components->error($refusal);
            }

            $this->components->error('This bundle must not be published.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Read %d files in %s; no key material, no secret, no database and no vendored dev tooling.',
            $files,
            $path,
        ));

        return self::SUCCESS;
    }
}
