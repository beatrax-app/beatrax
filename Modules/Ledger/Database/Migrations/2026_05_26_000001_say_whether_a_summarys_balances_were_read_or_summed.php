<?php

declare(strict_types=1);

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Ingestion\Public\Enums\SourceFormat;

// A summary either carries the balances its source printed or the balances the
// adapter summed from the rows it had just yielded, and the row said nothing
// about which. The difference is the whole of what a summed opening balance is
// worth as an anchor: it is zero by construction, not by measurement.

// Dated before 2026_05_27_000002 rather than on the day it was written, because
// that one-shot calls BackfillStartingBalanceFromStatementSummaries -- today's
// class, narrowing on today's column. A phone replays every migration in order
// and loads no schema dump, so a column added later is a column that migration
// would query before it exists.
/**
 * @link ../../../../.docs/features/mobile/architecture.md#the-migrations-only-a-phone-ever-runs
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $schema = $this->schema();
        if (! $schema->hasTable('statement_summaries') || ! $schema->hasTable('import_runs')) {
            return;
        }

        $schema->table('statement_summaries', static function (Blueprint $table): void {
            $table->boolean('balances_derived_from_rows')->default(false)->after('closing_balance_date');
        });

        // PayPal is the only adapter summing its own figures on the day this
        // column arrives, so the rows already written can be labelled from the
        // run that wrote them. Every later format answers at the adapter.
        $this->db()->connection($this->getConnection())
            ->table('statement_summaries')
            ->whereIn('import_run_id', static function (Builder $query): void {
                $query->select('id')
                    ->from('import_runs')
                    ->where('source_format', SourceFormat::PaypalCsv->value);
            })
            ->update(['balances_derived_from_rows' => true]);
    }

    public function down(): void
    {
        $schema = $this->schema();
        if (! $schema->hasTable('statement_summaries')) {
            return;
        }

        $schema->table('statement_summaries', static function (Blueprint $table): void {
            $table->dropColumn('balances_derived_from_rows');
        });
    }
};
