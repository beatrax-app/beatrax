<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\CardStatementStateMachine;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Public\Exceptions\CardStatementNotFoundException;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

uses(RefreshDatabase::class);

/*
 * Unit coverage for the CardStatementStateMachine lifecycle.
 *
 * Tests the four canonical transitions (settled / overpaid /
 * partially_settled / no-op) plus the cross-user safety guard.
 *
 * Pest dataset shape mirrors the IcsAmountParserTest fixture pattern
 * — one row per transition, single closure body, parameterised
 * expected outputs.
 */

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->conn = $db->connection();

    $this->user = User::query()->create([
        'username' => 'csm',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->otherUser = User::query()->create([
        'username' => 'csm-other',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->icsAccount = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'ICS state-machine',
        'slug' => 'csm-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/csm.pdf',
        'sha256' => str_repeat('m', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $statement = CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->icsAccount->id,
        'import_run_id' => $this->run->id,
        'period_start' => '2026-04-15 00:00:00',
        'period_end' => '2026-05-14 23:59:59',
        'total_amount_minor' => -84732,
        'open_balance_minor' => 84732,
        'state' => 'open',
    ]);
    $this->statementId = (int) $statement->id;

    /** @var CardStatementStateMachine $machine */
    $machine = $this->app->make(CardStatementStateMachine::class);
    $this->machine = $machine;
});

dataset('state_transitions', [
    'clean settles full balance' => [
        'delta' => 84732,
        'expectedOpen' => 0,
        'expectedState' => 'settled',
    ],
    'overpaid by €1.53' => [
        'delta' => 84885,
        'expectedOpen' => -153,
        'expectedState' => 'overpaid',
    ],
    'underpaid by €2.18 (partial)' => [
        'delta' => 84514,
        'expectedOpen' => 218,
        'expectedState' => 'partially_settled',
    ],
    'no-op delta keeps the previous open state' => [
        'delta' => 0,
        'expectedOpen' => 84732,
        'expectedState' => 'open',
    ],
]);

it('applies the documented lifecycle transition per dataset row', function (int $delta, int $expectedOpen, string $expectedState): void {
    $result = $this->machine->applySettlement($this->statementId, $delta, $this->user);

    expect($result->statementId)->toBe($this->statementId);
    expect($result->previousOpenMinor)->toBe(84732);
    expect($result->newOpenMinor)->toBe($expectedOpen);
    expect($result->newState)->toBe($expectedState);

    /** @var CardStatement $row */
    $row = CardStatement::query()->findOrFail($this->statementId);
    expect($row->open_balance_minor)->toBe($expectedOpen);
    expect($row->state)->toBe($expectedState);
})->with('state_transitions');

it('refuses to apply settlement against another user\'s statement and leaves the row untouched', function (): void {
    // The cross-user case raises the same type as a genuinely absent row,
    // on purpose: distinguishing them would confirm that another user's
    // statement exists.
    expect(fn () => $this->machine->applySettlement($this->statementId, 84732, $this->otherUser))
        ->toThrow(CardStatementNotFoundException::class);

    // Confirm the row is unchanged — no partial write leaked through
    // the transaction frame.
    /** @var CardStatement $row */
    $row = CardStatement::query()->findOrFail($this->statementId);
    expect($row->open_balance_minor)->toBe(84732);
    expect($row->state)->toBe('open');
});

it('binds CardStatementStateMachine as a singleton via ChainsServiceProvider', function (): void {
    $first = $this->app->make(CardStatementStateMachine::class);
    $second = $this->app->make(CardStatementStateMachine::class);
    expect($first)->toBe($second);
});
