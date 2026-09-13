<?php

declare(strict_types=1);

namespace Modules\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\OwnerAccount;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Merge\StrandedCreateRepair;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\SealedProjectionReadiness;

/**
 * @link ../../../.docs/features/sync/architecture.md#rows-the-log-holds-and-the-table-does-not
 */
final class SyncRepairStrandedCreatesCommand extends Command
{
    private const string ROW_FORMAT = '  %-10s %-8s %s';

    protected $signature = 'sync:repair-stranded-creates
        {--user= : The account to repair; defaults to the installation owner}
        {--table=counterparties : The replicated table whose stranded creates to take again}
        {--apply : Write the repair; without it nothing on this device changes}';

    protected $description = 'Take again the creates the operation log holds for rows no table here has.';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly StrandedCreateRepair $repair,
        private readonly SealedProjectionReadiness $readiness,
        private readonly DeviceRegistryService $registry,
        private readonly Container $container,
        private readonly OwnerAccount $owner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $userId = $this->resolveUserId();

        if ($userId === null) {
            $this->error('No account to repair: pass --user, or install one first.');

            return self::FAILURE;
        }

        $table = $this->tableOption();
        $plans = $this->repair->plan($table, $userId);

        $this->report($table, $plans, $userId);

        if ($plans === [] || ! $this->option('apply')) {
            $this->line($plans === [] ? 'Nothing to repair.' : 'Dry run: nothing was changed. Pass --apply to write it.');

            return self::SUCCESS;
        }

        return $this->apply($table, $plans, $userId);
    }

    // The natural key is printed although `StrandedCreateHealthCheck`
    // deliberately prints no value: that row is unsolicited and pasted into bug
    // reports, and this is an operator asking which rows return. Every column of
    // one is unsealed anyway — `PeerRowAliases` will not match on ciphertext.
    /**
     * @param  list<array{pk: string, devices: list<string>, naturalKey: string|null, action: string}>  $plans
     */
    private function report(string $table, array $plans, int $userId): void
    {
        $this->line(sprintf(
            '%s: %d create(s) the log holds that no row of account %d answers for.',
            $table,
            count($plans),
            $userId,
        ));

        if ($plans !== []) {
            $this->line(sprintf(self::ROW_FORMAT, 'pk', 'action', 'natural key'));
        }

        foreach ($plans as $plan) {
            $this->line(sprintf(self::ROW_FORMAT, $plan['pk'], $plan['action'], self::readable($plan['naturalKey'])));
        }
    }

    // Two ends, one decision, and the key is what decides: with it the row is
    // placed here and now through the applier, without it the coordinate is
    // recorded and the app's own recovery pass places it whole.
    /**
     * @param  list<array{pk: string, devices: list<string>, naturalKey: string|null, action: string}>  $plans
     */
    private function apply(string $table, array $plans, int $userId): int
    {
        $session = $this->container->make(Session::class);

        if (! $this->readiness->canProject($userId, $session)) {
            return $this->oweThemToTheRecoveryPass($table, $plans, $userId);
        }

        $placed = $this->repair->replay($plans, $this->replayerFor($userId), $table, $userId);

        $this->info(sprintf('%d row(s) placed in %s, each under an id this device minted with the peer\'s aliased to it.', $placed, $table));

        return self::SUCCESS;
    }

    // A console holds no app-lock key, so the peer's sealed columns cannot be
    // opened and a row written without them comes back unreadable and stays
    // that way -- `SplitCreateTail` fills a column holding null, never one
    // holding the wrong bytes. So nothing is written to the ledger here.
    /**
     * @param  list<array{pk: string, devices: list<string>, naturalKey: string|null, action: string}>  $plans
     */
    private function oweThemToTheRecoveryPass(string $table, array $plans, int $userId): int
    {
        $now = $this->container->make(Clock::class)->now()->toDateTimeString();
        ['written' => $written, 'owed' => $owed] = $this->repair->hold($plans, $table, $userId, $now);

        $this->warn(sprintf('Nothing was written to %s: this process holds no app-lock key.', $table));
        $this->line('A create carries the peer\'s sealed name and IBAN, and a row stored without them would come back unreadable for good.');
        $this->info(sprintf('%d create(s) recorded as owed, %d owed in total.', $written, $owed));
        $this->line('Open the app with the lock off and they are placed whole: the desktop runs its sealed-ledger recovery after every response, and the Devices & Sync screen runs it on open.');

        // A short run is a coordinate that is not there, and the next run takes
        // it: the count is read back off the table rather than off the write.
        if ($owed < count($plans)) {
            $this->warn(sprintf('%d of %d are owed. Run this again — a hold that did not land is written on the next pass.', $owed, count($plans)));
        }

        return self::SUCCESS;
    }

    // Built the way `HistoryReprojector` builds it: the verification map read
    // for this user explicitly, never the container's idea of who is signed
    // in, because a command has no request at all.
    private function replayerFor(int $userId): OpLogReplayer
    {
        return new OpLogReplayer(
            db: $this->db,
            deviceKeys: $this->registry->signatureVerificationKeys($userId),
            deviceKeysUserId: $userId,
            rules: $this->container->make(MergeRulesRegistry::class),
            searchWriter: $this->container->bound(SearchIndexWriterContract::class)
                ? $this->container->make(SearchIndexWriterContract::class)
                : null,
        );
    }

    private function tableOption(): string
    {
        $given = $this->option('table');

        return is_string($given) && $given !== '' ? $given : 'counterparties';
    }

    private function resolveUserId(): ?int
    {
        $given = $this->option('user');

        if (is_string($given) && $given !== '') {
            return is_numeric($given) ? (int) $given : null;
        }

        return $this->owner->id();
    }

    // `PeerRowAliases` joins a key's parts with a NUL, which a terminal draws
    // as nothing at all -- two columns' values running together as one word.
    private static function readable(?string $key): string
    {
        return $key === null ? 'no natural key — skipped' : str_replace("\0", ', ', $key);
    }
}
