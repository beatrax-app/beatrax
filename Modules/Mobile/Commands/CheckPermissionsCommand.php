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

        $requested = $permissions->requestedIn((string) file_get_contents($path));

        // An empty read is the shape a changed aapt2 output takes, and it
        // would otherwise pass every rule by naming none of them.
        return $requested === []
            ? ['mobile:check-permissions: the dump names no permission at all.']
            : $permissions->refusals($requested);
    }
}
