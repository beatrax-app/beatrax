<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Categorization\Internal\Services\ActiveRuleSet;
use Modules\Categorization\Internal\Services\RuleEngine;
use Modules\Categorization\Internal\Services\RuleMatchInput;
use Modules\Core\Models\User;

const RULE_BOOK_SIZE = 40;

const ROWS_MATCHED = 50;

// The pre-fix engine issued one condition query per rule per row plus one
// action query per firing rule, so this ceiling is what separates "read the
// book once" from "read it again for every transaction in the ledger".
const QUERY_CEILING = 8;

function trbUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function trbRule(DatabaseManager $db, int $userId, int $priority, string $needle): int
{
    $ruleId = $db->connection()->table('categorization_rules')->insertGetId([
        'user_id' => $userId,
        'active' => true,
        'priority' => $priority,
        'combinator' => 'all',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('rule_conditions')->insert([
        'rule_id' => $ruleId,
        'field' => 'counterparty',
        'op' => 'contains',
        'value_type' => 'string',
        'value' => $needle,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $db->connection()->table('rule_actions')->insert([
        'rule_id' => $ruleId,
        'position' => 1,
        'type' => 'note',
        'payload' => json_encode(['note' => $needle]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $ruleId;
}

function trbInput(string $counterparty): RuleMatchInput
{
    return new RuleMatchInput(
        counterpartyName: $counterparty,
        description: 'a description',
        settledAmountMinor: -1234,
        settledCurrency: 'EUR',
        postedAt: CarbonImmutable::parse('2026-03-04'),
    );
}

it('reads the rule book once however many transactions are matched against it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = trbUser('trb-reader');

    for ($i = 1; $i <= RULE_BOOK_SIZE; $i++) {
        trbRule($db, (int) $user->id, $i, 'Merchant '.$i);
    }

    /** @var RuleEngine $engine */
    $engine = app(RuleEngine::class);

    DB::flushQueryLog();
    DB::enableQueryLog();
    for ($row = 0; $row < ROWS_MATCHED; $row++) {
        $engine->match(trbInput('Merchant 7 storefront'), $user);
    }
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(QUERY_CEILING);
});

it('fires exactly the rules whose condition the row satisfies', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = trbUser('trb-fires');

    $wanted = trbRule($db, (int) $user->id, 1, 'Acme');
    trbRule($db, (int) $user->id, 2, 'Globex');

    /** @var RuleEngine $engine */
    $engine = app(RuleEngine::class);
    $matched = $engine->match(trbInput('Acme Supplies BV'), $user);

    expect($matched)->toHaveCount(1)
        ->and($matched[0]->ruleId)->toBe($wanted)
        ->and($matched[0]->actions)->toHaveCount(1)
        ->and($matched[0]->actions[0]->type)->toBe('note');
});

it('never lets one readers rule book answer for another reader', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $alice = trbUser('trb-alice');
    $bob = trbUser('trb-bob');

    trbRule($db, (int) $alice->id, 1, 'Acme');

    /** @var RuleEngine $engine */
    $engine = app(RuleEngine::class);

    expect($engine->match(trbInput('Acme Supplies BV'), $alice))->toHaveCount(1)
        ->and($engine->match(trbInput('Acme Supplies BV'), $bob))->toHaveCount(0);
});

// The snapshot is instance-scoped, which is only safe while nothing holds an
// engine across a rule write. A singleton binding would make an edit invisible
// until the process restarted, so the container's answer is pinned here.
it('gives every resolution its own rule-book snapshot', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = trbUser('trb-fresh');

    $first = app(RuleEngine::class);
    expect($first->match(trbInput('Acme Supplies BV'), $user))->toHaveCount(0);

    trbRule($db, (int) $user->id, 1, 'Acme');

    expect(app(ActiveRuleSet::class))->not->toBe(app(ActiveRuleSet::class))
        ->and(app(RuleEngine::class)->match(trbInput('Acme Supplies BV'), $user))->toHaveCount(1);
});
