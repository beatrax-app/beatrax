<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Actions;

use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Migration\Internal\Parsers\ActualParser;
use Modules\Migration\Internal\Parsers\NynabParser;
use Modules\Migration\Internal\Parsers\Ynab4Parser;
use Modules\Migration\Internal\Pipeline\StagingWriter;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Contracts\ParsesMigrationSource;
use Modules\Migration\Public\Enums\MigrationRunStatus;
use Throwable;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class StartMigrationRun
{
    /** @var array<string, ParsesMigrationSource> */
    private readonly array $parsers;

    public function __construct(
        private readonly StagingWriter $stagingWriter,
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
            // Cascade-deletes every migration_staging_* row already written
            // for this run — a corrupt/partial parse leaves nothing behind.
            $run->delete();

            throw $e;
        }

        return $run;
    }
}
