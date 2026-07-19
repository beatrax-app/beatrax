<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds `account_uid` to `open_banking_connections` (Wave 2, T-19-09 carried
 * gap from 19-08): the Enable Banking `/sessions` response returns
 * `session_id` PLUS a linked `accounts[].uid` list, but nothing in the
 * module persisted the account uid anywhere — `EnableBankingSourceAdapter::
 * fetch()`'s first parameter IS that account uid (see that class's
 * docblock), so `OpenBankingFetchService` has no value to pass without this
 * column.
 *
 * NOT a secret (D-07 arch guard, `OpenBankingSecretsFileGuardTest`'s
 * migration-content grep test): `account_uid` is an opaque
 * aggregator-assigned account identifier, not a credential — it carries no
 * more sensitivity than `institution_id`, which already lives on this table.
 *
 * Single-account-per-connection assumption: `open_banking_connections` is
 * keyed one row per (user_id, institution_id) (19-05 migration docblock).
 * `OpenBankingCallbackController` persists only the FIRST entry of
 * `accounts[]` — extending to a multi-account-per-connection model is out
 * of this migration's (and this plan's) scope.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('open_banking_connections', static function (Blueprint $table): void {
            $table->string('account_uid', 128)->nullable()->after('institution_id');
        });
    }

    public function down(): void
    {
        $this->schema()->table('open_banking_connections', static function (Blueprint $table): void {
            $table->dropColumn('account_uid');
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
