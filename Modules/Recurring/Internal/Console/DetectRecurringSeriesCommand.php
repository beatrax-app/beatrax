<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Recurring\Internal\Jobs\DetectRecurringSeriesJob;

// The job's ShouldBeUniqueUntilProcessing lock keyed on userId collapses a
// same-day re-dispatch — this tick racing the on-demand button — into one pass.
final class DetectRecurringSeriesCommand extends Command
{
    use CoercesScalars;

    /** @var string */
    protected $signature = 'recurring:detect';

    /** @var string */
    protected $description = 'Run the recurring-series detection sweep once per user.';

    public function __construct(
        private readonly Dispatcher $bus,
        private readonly DatabaseManager $db,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        foreach ($this->db->connection()->table('users')->pluck('id') as $id) {
            $this->bus->dispatch(new DetectRecurringSeriesJob(self::toInt($id)));
        }

        return self::SUCCESS;
    }
}
