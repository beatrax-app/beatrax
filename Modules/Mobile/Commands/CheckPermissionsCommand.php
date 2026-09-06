<?php

declare(strict_types=1);

namespace Modules\Mobile\Commands;

use Illuminate\Console\Command;
use Modules\Mobile\Internal\Boot\ShippedPermissions;

final class CheckPermissionsCommand extends Command
{
    /** @var string */
    protected $signature = 'mobile:check-permissions {dump : a file holding `aapt2 dump permissions <apk>` output}';

    /** @var string */
    protected $description = 'Refuse a built Android artifact requesting a permission this product does not make the use for.';

    public function handle(ShippedPermissions $permissions): int
    {
        /** @var string $path */
        $path = $this->argument('dump');

        if (! is_file($path)) {
            $this->components->error('mobile:check-permissions: no dump at '.$path);

            return self::FAILURE;
        }

        $requested = $permissions->requestedIn((string) file_get_contents($path));

        // An empty read is the shape a changed aapt2 output takes, and it
        // would otherwise pass every rule below by naming nothing.
        if ($requested === []) {
            $this->components->error('mobile:check-permissions: the dump names no permission at all.');

            return self::FAILURE;
        }

        $refusals = $permissions->refusals($requested);

        if ($refusals !== []) {
            foreach ($refusals as $refusal) {
                $this->components->error($refusal);
            }

            return self::FAILURE;
        }

        $this->components->info('Requests '.count($requested).' permissions, each with a consumer that ships.');

        return self::SUCCESS;
    }
}
