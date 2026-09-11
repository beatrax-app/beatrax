<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\RowOwnership;
use Modules\Sync\Public\Services\DependentRowCascade;

uses(RefreshDatabase::class);

// The app deletes a parent's children so the database does not do it silently.
// A child table carrying no user_id was left unscoped, so the delete was
// bounded by the parent key alone -- correct today, because every caller has
// already established the parent, and one table away from not being.

function cascadeChildTables(): array
{
    $children = [];

    foreach (DependentRowCascade::ownedBy() as $keys) {
        foreach ($keys as $key) {
            $children[explode('.', $key)[0]] = true;
        }
    }

    return array_keys($children);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->cascade = new DependentRowCascade($db, new MergeRulesRegistry);
});

it('scopes the delete of a child that carries no owner of its own', function (): void {
    $userId = (int) $this->db->connection()->table('users')->insertGetId([
        'username' => 'cascade-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $ruleId = (int) $this->db->connection()->table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'priority' => 10,
        'combinator' => 'all',
        'hits_count' => 0,
        'active' => true,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $this->db->connection()->table('rule_actions')->insert([
        'rule_id' => $ruleId,
        'position' => 0,
        'type' => 'note',
        'payload' => json_encode(['note' => 'cascade fixture'], JSON_THROW_ON_ERROR),
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $this->db->connection()->enableQueryLog();
    $this->cascade->delete('categorization_rules', $ruleId, $userId);
    $log = $this->db->connection()->getQueryLog();
    $this->db->connection()->disableQueryLog();

    $deletes = array_values(array_filter(
        array_column($log, 'query'),
        static fn (string $sql): bool => str_contains($sql, 'delete from "rule_actions"'),
    ));

    // rule_actions reaches its owner only through categorization_rules, so a
    // scoped delete has to name that parent. Unscoped, the statement is
    // bounded by the rule id and nothing else.
    expect($deletes)->not->toBeEmpty()
        ->and($deletes[0])->toContain('categorization_rules');
});

it('refuses outright on a table it cannot attribute to anyone', function (): void {
    $this->db->connection()->statement('CREATE TABLE cascade_orphans (id integer primary key, rule_id integer)');
    $this->db->connection()->table('cascade_orphans')->insert(['id' => 1, 'rule_id' => 1]);

    $query = $this->db->connection()->table('cascade_orphans');
    (new RowOwnership($this->db))->scopeToUser($query, 'cascade_orphans', 1);

    expect($query->toSql())->toContain('1 = 0')
        ->and($query->count())->toBe(0);
});

it('can attribute every child a parent owns', function (): void {
    $children = cascadeChildTables();

    // The floor is the positive control: an empty list would make the loop
    // below a sentence about nothing.
    expect($children)->toHaveCount(38);

    $unscopable = [];

    foreach ($children as $table) {
        $query = $this->db->connection()->table($table);
        (new RowOwnership($this->db))->scopeToUser($query, $table, 1);

        if (str_contains($query->toSql(), '1 = 0')) {
            $unscopable[] = $table;
        }
    }

    expect($unscopable)->toBe([]);
});
