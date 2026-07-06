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
use Throwable;

/**
 * Entry point of the parse -> stage half of the migration wizard (Req 1/11):
 * given an already-extracted export directory, a declared source format, and
 * the owning user, this (1) creates the `migration_runs` row (`status`
 * `'parsed'`), (2) resolves the matching `ParsesMigrationSource`
 * implementation by `format()`, (3) parses the export into a `MigrationBatch`,
 * and (4) hands that batch to `StagingWriter` to land it in the
 * `migration_staging_*` scratch tables. NOTHING else is written — no domain
 * table is ever touched by this action (Req 11's no-write-before-confirm
 * invariant; promotion is `ConfirmMigration`'s job, Plan 06).
 *
 * `$extractedPath` is a plain filesystem directory — extracting an uploaded
 * ZIP (via `Internal\Parsers\Support\ZipExtractor`) happens upstream of this
 * action, in the wizard's own upload-handling step (Plan 08), exactly like
 * `ParsesMigrationSource::parse()`'s own contract docblock states.
 *
 * Req 1 reject-not-partial: a parser throws `UnrecognizedMigrationFileException`
 * before yielding any batch on a corrupt/unrecognized file, but the
 * transaction generator can ALSO throw lazily while `StagingWriter` is
 * consuming it (mid-parse column errors surfaced only once a later row is
 * reached). Either way, this action deletes the just-created `migration_runs`
 * row on ANY throw during parse-or-stage — the `migration_staging_*` FKs all
 * `cascadeOnDelete()` off `migration_run_id` (SQLite foreign-key enforcement
 * is explicitly enabled for both the real and testing connections via
 * `config/database.php`'s `foreign_key_constraints => true`), so deleting the
 * run wipes every partial staging row that may have already landed, leaving
 * both staging and domain tables clean.
 *
 * Deliberately NOT wrapped in one outer `DB::transaction()`: `StagingWriter`
 * commits its transaction rows in independent bounded chunks (mirrors
 * `RecordTransactions`'s "never one unbounded transaction for a multi-year
 * batch" convention — Shared Patterns). Nesting that inside a single
 * encompassing transaction here would collapse those chunk commits back into
 * one giant transaction, exactly what the chunking exists to avoid. This
 * action therefore has no explicit-timestamp column to stamp at creation
 * time either (`migration_runs.confirmed_at` stays null until Plan 06's
 * `ConfirmMigration` flips it) and so injects neither `Clock` nor
 * `DatabaseManager` — only `StagingWriter` and the three format parsers.
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
            'status' => 'parsed',
            'original_filename' => $originalFilename,
        ]);

        try {
            $batch = $parser->parse($extractedPath, $user, $run->id);
            $this->stagingWriter->write($batch, $run->id, $user);
        } catch (Throwable $e) {
            // Cascade-deletes every migration_staging_* row already written
            // for this run (Req 1 reject-not-partial) — nothing leaks.
            $run->delete();

            throw $e;
        }

        return $run;
    }
}
