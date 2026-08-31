<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\OpenBanking\Internal\Jobs\SyncOpenBankingAccountJob;
use Modules\OpenBanking\Internal\Support\ConsentWindow;

// "No fetch when the connection is off or consent has expired" is enforced at
// this WHERE clause, not only inside the job; the job re-checks both on pickup
// for the crash-between-dispatch-and-pickup race its own docblock documents.
final class SyncDueOpenBankingConnectionsCommand extends Command
{
    use CoercesScalars;

    /** @var string */
    protected $signature = 'open-banking:sync-due';

    /** @var string */
    protected $description = 'Sync every enabled open-banking connection whose consent is still live.';

    public function __construct(
        private readonly Dispatcher $bus,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connectionIds = ConsentWindow::constrainToLive(
            $this->db->connection()->table('open_banking_connections')->where('enabled', true),
            $this->clock->now(),
        )->pluck('id');

        foreach ($connectionIds as $id) {
            $this->bus->dispatch(new SyncOpenBankingAccountJob(self::toInt($id)));
        }

        return self::SUCCESS;
    }
}
