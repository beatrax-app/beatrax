<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Contracts\ParsesMigrationSource;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Parsers\ActualParser;
use Modules\Migration\Internal\Parsers\NynabParser;
use Modules\Migration\Internal\Parsers\Ynab4Parser;
use Modules\Migration\Internal\Pipeline\StagingWriter;
use Modules\Migration\Models\MigrationRun;
use Modules\Sync\Public\Services\DependentRowCascade;
use Throwable;

final readonly class StartMigrationRun
{
    /** @var array<string, ParsesMigrationSource> */
    private array $parsers;

    public function __construct(
        private StagingWriter $stagingWriter,
        private Dispatcher $events,
        private DependentRowCascade $cascade,
        Ynab4Parser $ynab4Parser,
        NynabParser $nynabParser,
        ActualParser $actualParser,
    ) {
        $this->parsers = [
            $ynab4Parser->format() => $ynab4Parser,
            $nynabParser->format() => $nynabParser,
            $actualParser->format() => $actualParser,
        ];
    }

    public function __invoke(User $user, string $sourceProduct, string $extractedPath, string $originalFilename): MigrationRun
    {
        $parser = $this->parsers[$sourceProduct] ?? null;
        if ($parser === null) {
            throw new InvalidArgumentException("Unknown migration source format: '{$sourceProduct}'.");
        }

        $run = MigrationRun::create([
            'user_id' => $user->id,
            'source_product' => $sourceProduct,
            'status' => MigrationRunStatus::Parsed->value,
            'original_filename' => $originalFilename,
        ]);

        try {
            $batch = $parser->parse($extractedPath, $user, $run->id);
            $this->stagingWriter->write($batch, $run->id, $user);
        } catch (Throwable $e) {
            $this->discardPartialRun($run, $user);

            throw $e;
        }

        return $run;
    }

    // The database used to clear these rows on the parent delete and no longer
    // does, so the delete refused while a part-written parse still held staging
    // rows — and the foreign-key error took the place of the parse failure that
    // caused it, on the screen and in the log alike.
    private function discardPartialRun(MigrationRun $run, User $user): void
    {
        foreach ($this->cascade->delete('migration_runs', $run->id, $user->id) as $event) {
            $this->events->dispatch($event);
        }

        $run->delete();
    }
}
