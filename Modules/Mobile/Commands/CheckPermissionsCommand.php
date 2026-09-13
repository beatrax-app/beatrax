<?php

declare(strict_types=1);

namespace Modules\Mobile\Commands;

use Illuminate\Console\Command;
use Modules\Mobile\Internal\Boot\ShippedPermissions;

final class CheckPermissionsCommand extends Command
{
    /** @var string */
    protected $signature = 'mobile:check-permissions {dump : a file holding `aapt2 dump xmltree <apk> --file AndroidManifest.xml` output}';

    /** @var string */
    protected $description = 'Refuse a built Android artifact requesting a permission this product does not make the use for.';

    public function handle(ShippedPermissions $permissions): int
    {
        /** @var string $path */
        $path = $this->argument('dump');

        $refusals = $this->refusals($permissions, $path);

        if ($refusals !== []) {
            foreach ($refusals as $refusal) {
                $this->components->error($refusal);
            }

            return self::FAILURE;
        }

        $this->components->info('Every permission the artifact requests has a consumer that ships.');

        return self::SUCCESS;
    }

    // The three ways this can refuse, answered as one list: a caller reading
    // them wants the reason, not which of the three produced it.
    /**
     * @return list<string>
     */
    private function refusals(ShippedPermissions $permissions, string $path): array
    {
        if (! is_file($path)) {
            return ['mobile:check-permissions: no dump at '.$path];
        }

        $manifest = $permissions->readManifest((string) file_get_contents($path));

        // An empty read is the shape a changed aapt2 output takes, and it
        // would otherwise pass every rule by naming none of them. The package
        // is asked for too: without it a self-declared permission cannot be
        // told from one an outside authority owns, and the rule turns on that.
        if ($manifest['requested'] === [] || $manifest['package'] === '') {
            return ['mobile:check-permissions: the dump names no permission, or no package to scope one against.'];
        }

        return $permissions->refusals($manifest);
    }
}
