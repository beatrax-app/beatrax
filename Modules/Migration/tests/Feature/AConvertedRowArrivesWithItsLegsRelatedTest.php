<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Ledger\Public\ValueObjects\Rate;
use Modules\Migration\Internal\Pipeline\PromoteStagingToDomain;

uses(RefreshDatabase::class);

// migration_staging_transactions carries a native pair and a settled pair as
// four separate columns, and the promote passed all four to the insert verbatim
// beside a hardcoded null rate. A source that books each leg by the balance IT
// moved hands over a settled credit for a native debit: the row landed as a
// −$30.00 expense whose settled leg was +€27.23, which every balance, budget
// and report sums as income, under no rate at all.

beforeEach(function (): void {
    /** @var DatabaseManager $manager */
    $manager = app(DatabaseManager::class);
    $this->conn = $manager->connection();

    $this->user = User::create([
        'username' => 'converted-promote',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->runId = (int) $this->conn->table('migration_runs')->insertGetId([
        'user_id' => $this->user->id,
        'source_product' => 'ynab4',
        'status' => 'parsed',
        'original_filename' => 'fixture.zip',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->conn->table('migration_staging_accounts')->insert([
        'user_id' => $this->user->id,
        'migration_run_id' => $this->runId,
        'source_external_id' => 'acct-1',
        'name' => 'Travel Card',
        'kind' => 'bank',
        'currency' => 'EUR',
        'resolution_status' => 'unmapped',
    ]);

    $this->stageRow = function (string $id, int $amountMinor, string $currency, int $settledMinor, string $settledCurrency): void {
        $this->conn->table('migration_staging_transactions')->insert([
            'user_id' => $this->user->id,
            'migration_run_id' => $this->runId,
            'source_external_id' => $id,
            'account_source_external_id' => 'acct-1',
            'posted_at' => '2026-03-04 00:00:00',
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'settled_amount_minor' => $settledMinor,
            'settled_currency' => $settledCurrency,
            'description' => 'Hotel',
            'cleared_status' => 'cleared',
            'is_split_parent' => false,
            'parent_source_external_id' => null,
            'category_source_external_id' => null,
        ]);
    };

    $this->promoted = function (): stdClass {
        app(PromoteStagingToDomain::class)->promote($this->runId, $this->user);

        /** @var stdClass */
        return $this->conn->table('transactions')
            ->where('user_id', $this->user->id)
            ->sole(['amount_minor', 'currency', 'settled_amount_minor', 'settled_currency', 'fx_rate_used']);
    };
});

it('gives a promoted converted row one sign and the rate its own two legs name', function (): void {
    ($this->stageRow)('tx-1', -3000, 'USD', 2723, 'EUR');

    $row = ($this->promoted)();

    expect((int) $row->amount_minor)->toBe(-3000)
        ->and((string) $row->currency)->toBe('USD')
        ->and((int) $row->settled_amount_minor)->toBe(-2723)
        ->and((string) $row->settled_currency)->toBe('EUR')
        ->and((string) $row->fx_rate_used)->toBe(
            (string) Rate::between(Money::ofMinor(-2723, 'EUR'), Money::ofMinor(-3000, 'USD')),
        );
});

it('leaves a single-currency promoted row its own two equal legs and no rate', function (): void {
    ($this->stageRow)('tx-2', -1250, 'EUR', -1250, 'EUR');

    $row = ($this->promoted)();

    expect((int) $row->amount_minor)->toBe(-1250)
        ->and((int) $row->settled_amount_minor)->toBe(-1250)
        ->and($row->fx_rate_used)->toBeNull();
});
