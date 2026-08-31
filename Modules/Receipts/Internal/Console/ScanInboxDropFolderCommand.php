<?php

declare(strict_types=1);

namespace Modules\Receipts\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Modules\Core\Models\User;
use Modules\Receipts\Internal\Jobs\ScanInboxDropFolderJob;

// The opt-in is read here rather than inside the job so a reader who never
// turned the drop folder on costs the tick no queued job at all — on a phone
// the runner's window is short enough that an empty dispatch is not free.
final class ScanInboxDropFolderCommand extends Command
{
    /** @var string */
    protected $signature = 'receipts:scan-drop-folder';

    /** @var string */
    protected $description = 'Scan every opted-in reader\'s inbox-drop folder for .eml and .mbox files.';

    public function __construct(
        private readonly Dispatcher $bus,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        User::query()
            ->where('auto_import_drop_folder', true)
            ->lazyById(100)
            ->each(function (User $user): void {
                $this->bus->dispatch(new ScanInboxDropFolderJob($user->id));
            });

        return self::SUCCESS;
    }
}
