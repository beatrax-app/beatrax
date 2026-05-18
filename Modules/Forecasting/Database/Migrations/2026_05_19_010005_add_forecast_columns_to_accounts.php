<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds three nullable columns to Ledger's accounts table for the
 * per-account forecast buffer + user-input opening-balance fallback.
 * The columns are read by Forecasting's BalanceAnchorResolver + the
 * /settings + /forecast buffer editors. Ledger does not read or write
 * these columns.
 *
 *   - forecast_min_buffer_minor — nullable BIGINT; NULL = no buffer =
 *     zero-crossing default.
 *   - opening_balance_minor — nullable BIGINT; user-input fallback for
 *     PayPal / CSV-only sources without a settled statement summary.
 *   - opening_balance_as_of_date — ISO date the opening balance applies
 *     to (the day the user entered the figure).
 *
 * This migration lives in Modules/Forecasting/Database/Migrations/
 * because the column additions are Forecasting-owned even though the
 * physical table is owned by Ledger.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('accounts', static function (Blueprint $table): void {
            $table->bigInteger('forecast_min_buffer_minor')
                ->nullable()
                ->after('default_currency');
            $table->bigInteger('opening_balance_minor')
                ->nullable()
                ->after('forecast_min_buffer_minor');
            $table->date('opening_balance_as_of_date')
                ->nullable()
                ->after('opening_balance_minor');
        });
    }

    public function down(): void
    {
        $this->schema()->table('accounts', static function (Blueprint $table): void {
            $table->dropColumn([
                'forecast_min_buffer_minor',
                'opening_balance_minor',
                'opening_balance_as_of_date',
            ]);
        });
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
