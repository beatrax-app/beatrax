<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tax\Internal\Services\TaxYearQuery;

uses(RefreshDatabase::class);

// The gate stops new ones; these are the rows tagged before it existed. A
// sweep that reports clean on a clean tree says nothing, so the case that
// matters is the one carrying tags the rule now refuses.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;
    $this->userId = ownAccountsUser($db, 'sweep-'.bin2hex(random_bytes(3)));
});

function sweepTagDirectly(DatabaseManager $db, int $userId, int $txId): void
{
    $db->connection()->table('tax_transaction_tags')->insert([
        'user_id' => $userId,
        'transaction_id' => $txId,
        'deduction_category_id' => null,
        'note' => null,
        'tax_year_override' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('reports what it would remove and changes nothing until it is told to', function (): void {
    $transfer = ownAccountsRow($this->db, $this->userId, 'transfer_out', -50000, 'transfer');
    sweepTagDirectly($this->db, $this->userId, $transfer);

    $this->artisan('tax:sweep-untaggable')
        ->expectsOutputToContain('1 tag(s) would be removed.')
        ->assertSuccessful();

    expect($this->db->connection()->table('tax_transaction_tags')->count())->toBe(1);
});

it('removes only the tags the rule refuses, and takes their weight off the total', function (): void {
    $deductible = ownAccountsRow($this->db, $this->userId, 'expense', -12500);
    $transfer = ownAccountsRow($this->db, $this->userId, 'transfer_out', -50000, 'transfer');
    $correction = ownAccountsRow($this->db, $this->userId, 'adjustment', 500);

    foreach ([$deductible, $transfer, $correction] as $txId) {
        sweepTagDirectly($this->db, $this->userId, $txId);
    }

    expect(app(TaxYearQuery::class)->forUser($this->userId, 2026)->deductionsTotalMinor)->toBe(63000);

    $this->artisan('tax:sweep-untaggable', ['--apply' => true])->assertSuccessful();

    expect($this->db->connection()->table('tax_transaction_tags')->pluck('transaction_id')->all())->toBe([$deductible])
        ->and(app(TaxYearQuery::class)->forUser($this->userId, 2026)->deductionsTotalMinor)->toBe(12500);
});
