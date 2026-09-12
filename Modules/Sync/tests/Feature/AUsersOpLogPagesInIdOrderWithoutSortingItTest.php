<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Support\RowChunk;

uses(RefreshDatabase::class);

// Every bulk walk of `op_log_entries` pages with `where user_id = ? and id > ?
// order by id`. Both indexes the table shipped with lead on user_id and then
// carry the HLC, so the planner took one and sorted the user's whole log into a
// temp b-tree for the id order — once per chunk, which is quadratic in the log.

const OP_LOG_PAGING_ROWS = 3 * RowChunk::DEFAULT_SIZE;

function opLogPagingUser(): User
{
    return User::query()->create([
        'username' => 'op-log-paging-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function opLogPagingSeed(DatabaseManager $db, int $userId): void
{
    $connection = $db->connection();
    $rows = [];

    for ($i = 0; $i < OP_LOG_PAGING_ROWS; $i++) {
        $rows[] = [
            'user_id' => $userId,
            'device_id' => 'device-paging',
            'table_name' => 'transactions',
            'pk' => (string) ($i + 1),
            'field' => 'note',
            'op_type' => 'set',
            'value' => json_encode('handmatige notitie '.$i, JSON_THROW_ON_ERROR),
            'hlc_l' => 1_700_000_000_000 + $i,
            'hlc_c' => 0,
            'signature' => 'signature-paging-'.$i,
            'recorded_at' => now(),
        ];

        if (count($rows) === 200) {
            $connection->table('op_log_entries')->insert($rows);
            $rows = [];
        }
    }

    if ($rows !== []) {
        $connection->table('op_log_entries')->insert($rows);
    }
}

it('plans every ordered op-log page the enable-time pass issues against an index, never a sort', function (): void {
    $user = opLogPagingUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $connection = $db->connection();

    opLogPagingSeed($db, (int) $user->id);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $pages = [];
    // The EXPLAIN below re-enters this listener on the very SQL it is
    // explaining, so the prefix is excluded rather than the list re-read.
    $connection->listen(function (QueryExecuted $query) use (&$pages): void {
        if (str_starts_with($query->sql, 'EXPLAIN')) {
            return;
        }

        if (str_contains($query->sql, 'from "op_log_entries"') && str_contains($query->sql, 'order by "id"')) {
            $pages[] = [$query->sql, $query->bindings];
        }
    });

    /** @var EncryptionMigrationService $migration */
    $migration = $this->app->make(EncryptionMigrationService::class);
    $migration->migrate($user, $session);

    // The snapshot walks the log and so does the sweep, each in pages of
    // RowChunk::DEFAULT_SIZE, so three chunks' worth of rows is at least six.
    $walked = count($pages);
    expect($walked)->toBeGreaterThanOrEqual(6);

    $sorted = [];
    $indexed = 0;

    foreach ($pages as [$sql, $bindings]) {
        $details = array_map(
            static fn (stdClass $step): string => (string) $step->detail,
            $connection->select('EXPLAIN QUERY PLAN '.$sql, $bindings),
        );

        $plan = implode(' || ', $details);

        if (str_contains($plan, 'TEMP B-TREE')) {
            $sorted[] = $plan;
        }

        if (str_contains($plan, 'op_log_entries_user_id_index')) {
            $indexed++;
        }
    }

    expect($sorted)->toBe([]);
    expect($indexed)->toBe($walked);
});

it('carries an index on user_id alone, which is what answers the filter and the id order together', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $columns = [];

    foreach ($db->connection()->select('PRAGMA index_info("op_log_entries_user_id_index")') as $column) {
        $columns[] = (string) $column->name;
    }

    // Not (user_id, id): SQLite appends the rowid to every index entry and
    // `id` IS the rowid here, so naming it a second time would only widen the
    // index. The plan above is what proves the ordering comes out of it.
    expect($columns)->toBe(['user_id']);
});
