<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Account\OwedKeyMaterial;
use Psr\Log\LoggerInterface;

// The other half of a deletion whose rows committed and whose unlinks did not.
// Two ways in: the unlink was refused, which is a held handle and is usually
// released later; or the process died between the commit and the unlink. Both
// leave a row naming an account this device no longer has but still has keys for.
/**
 * @link ../../../../.docs/features/auth/user-scoped-purge.md#the-two-file-tiers-and-why-neither-is-inside-the-transaction
 */
final class SweepOwedKeyMaterialCommand extends Command
{
    /** @var string */
    protected $signature = 'auth:sweep-owed-key-material';

    /** @var string */
    protected $description = 'Unlink the sync identity, keyring and connector secret of an account whose deletion committed without finishing.';

    // Resolved on demand, never injected: Artisan builds every command to
    // assemble its list, and a graph built at that moment is held for the life
    // of the process with whatever configuration it froze.
    public function __construct(
        private readonly Container $container,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $owed = $this->container->make(OwedKeyMaterial::class);
        $connection = $this->container->make(DatabaseManager::class)->connection();
        $log = $this->container->make(LoggerInterface::class);

        foreach ($owed->accountsStillOwed($connection) as $accountId) {
            $survivors = $owed->settle($connection, $accountId);

            if ($survivors !== []) {
                $log->error('SweepOwedKeyMaterialCommand: key material of a deleted account is still on this disk.', [
                    'user_id' => $accountId,
                    'paths' => $survivors,
                ]);
            }
        }

        return self::SUCCESS;
    }
}
