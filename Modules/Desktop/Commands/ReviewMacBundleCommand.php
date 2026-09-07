<?php

declare(strict_types=1);

namespace Modules\Desktop\Commands;

use Illuminate\Console\Command;
use Modules\Desktop\Internal\Boot\MacBundleInspection;

final class ReviewMacBundleCommand extends Command
{
    // The executables this product spawns that no bundle structure reveals. An
    // Electron helper under Frameworks is found by shape; the interpreter looks
    // exactly like a bundled data file that happens to be Mach-O.

    // Both places, because the store lane moves it: naming only where it used
    // to live would stop checking the one file this whole review is about, on
    // the very build that relocated it.
    /** @var list<string> */
    public const array INTERPRETERS = [
        'Contents/Resources/build/php/php',
        'Contents/MacOS/php',
    ];

    /** @var string */
    protected $signature = 'desktop:review-mac-bundle {path : the built .app to read}';

    /** @var string */
    protected $description = 'Refuse a macOS bundle App Store review would refuse, by reading the bundle rather than the build config.';

    public function handle(MacBundleInspection $inspection): int
    {
        /** @var string $path */
        $path = $this->argument('path');

        // A path that is not there is not a bundle that passed. Reporting
        // success for a missing directory is how a renamed build output turns
        // into a green submission.
        if (! is_dir($path)) {
            $this->components->error('desktop:review-mac-bundle: no bundle at '.$path);

            return self::FAILURE;
        }

        $executables = $inspection->executableCount($path);

        // Nothing read is not nothing wrong. A walk that found no Mach-O at
        // all means the path is not an app bundle, and it would otherwise
        // report exactly like a bundle with no findings.
        if ($executables === 0) {
            $this->components->error('desktop:review-mac-bundle: found no executable at all in '.$path);

            return self::FAILURE;
        }

        return $this->report($inspection->refusals($path, self::INTERPRETERS), $executables, $path);
    }

    /** @param list<string> $refusals */
    private function report(array $refusals, int $executables, string $path): int
    {
        if ($refusals !== []) {
            foreach ($refusals as $refusal) {
                $this->components->error($refusal);
            }

            $this->components->error('This bundle must not be submitted to the Mac App Store.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('Read %d executables in %s; nothing App Store review refuses.', $executables, $path));

        return self::SUCCESS;
    }
}
