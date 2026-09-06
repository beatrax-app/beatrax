<?php

declare(strict_types=1);

namespace Modules\Sync\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\OwnerAccount;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogRebuilder;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Throwable;

/**
 * @link ../../../.docs/features/sync/architecture.md
 */
final class SyncRebuildCommand extends Command
{
    protected $signature = 'sync:rebuild
        {--user= : The account to rebuild; defaults to the installation owner}
        {--force : Skip the confirmation}';

    protected $description = 'Rebuild every replicated table by replaying the merged operation log from scratch.';

    public function __construct(
        private readonly DatabaseManager $db,
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
            $this->error('No account to rebuild: pass --user, or install one first.');

            return self::FAILURE;
        }

        // The whole thing runs in one transaction and restores its triggers on
        // any throw, so the risk is time rather than loss — but it deletes every
        // replicated row before replaying, and that is worth saying out loud.
        if (! $this->option('force') && ! $this->confirm(
            'Rebuild deletes every replicated row for this account and replays the log over it. Continue?',
            false,
        )) {
            $this->line('Nothing was rebuilt.');

            return self::SUCCESS;
        }

        return $this->rebuildAndReport($userId);
    }

    // Split from handle() so the decisions — is there an account, did the
    // operator agree — read as decisions, and the work reads as work.
    private function rebuildAndReport(int $userId): int
    {
        try {
            $this->rebuilderFor($userId)->rebuild($userId);
        } catch (Throwable $e) {
            // Rolled back already, so this says what happened rather than
            // warning about a half-rebuilt database. Described rather than
            // quoted: a QueryException's message is the statement and its
            // bindings, and console output gets redirected into logs.
            $described = SafeExceptionContext::describe($e);

            $this->error(
                'Rebuild failed and was rolled back; the database is as it was. '
                .$described['reason']
                .($described['sqlstate'] === '' ? '' : ' ('.$described['sqlstate'].')'),
            );

            return self::FAILURE;
        }

        $this->info('Rebuilt every replicated table for account '.$userId.' from the operation log.');

        return self::SUCCESS;
    }

    private function resolveUserId(): ?int
    {
        $given = $this->option('user');

        if (is_string($given) && $given !== '') {
            return is_numeric($given) ? (int) $given : null;
        }

        return $this->owner->id();
    }

    // Built the way HistoryReprojector and SyncWebSocketHandler build it: the
    // verification map read for this user explicitly, never the container's
    // idea of who is signed in, because a command has no request at all.
    private function rebuilderFor(int $userId): OpLogRebuilder
    {
        $registry = $this->container->make(MergeRulesRegistry::class);

        return new OpLogRebuilder(
            db: $this->db,
            // No search writer on the replayer: the rebuilder suppresses FTS
            // writes during the replay and re-derives the index afterwards,
            // which is why the writer goes to the rebuilder instead.
            replayer: new OpLogReplayer(
                db: $this->db,
                deviceKeys: $this->registry->signatureVerificationKeys($userId),
                rules: $registry,
            ),
            registry: $registry,
            searchWriter: $this->container->bound(SearchIndexWriterContract::class)
                ? $this->container->make(SearchIndexWriterContract::class)
                : null,
        );
    }
}
