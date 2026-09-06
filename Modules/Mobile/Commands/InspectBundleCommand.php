<?php

declare(strict_types=1);

namespace Modules\Mobile\Commands;

use Illuminate\Console\Command;
use Modules\Mobile\Internal\Boot\ShippedBundleContents;

final class InspectBundleCommand extends Command
{
    /** @var string */
    protected $signature = 'mobile:inspect-bundle {path : the built .apk, .aab or .ipa to read}';

    /** @var string */
    protected $description = 'Refuse a built mobile artifact that carries key material, a secret or a database.';

    public function handle(ShippedBundleContents $contents): int
    {
        /** @var string $path */
        $path = $this->argument('path');

        // A path that is not there is not an artifact that passed: the check
        // exists to be run on a build, and reporting success for a missing
        // file is how a renamed output turns into a green submission.
        if (! is_file($path)) {
            $this->components->error('mobile:inspect-bundle: no artifact at '.$path);

            return self::FAILURE;
        }

        $refusals = $contents->refusals($path);

        if ($refusals !== []) {
            foreach ($refusals as $refusal) {
                $this->components->error($refusal);
            }

            $this->components->error('This artifact must not be submitted or published.');

            return self::FAILURE;
        }

        $this->components->info('Carries no key material, no secret and no database: '.$path);

        return self::SUCCESS;
    }
}
