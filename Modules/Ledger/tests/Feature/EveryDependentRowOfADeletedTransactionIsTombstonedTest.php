<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Contracts\DeletesTransaction;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Events\TransactionMutated;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    // Faked for the same reason the sibling delete test fakes them: the capture
    // listener wants an OpLogWriter with runtime device creds nothing binds here.
    Event::fake([TransactionMutated::class, EntityMutated::class]);

    /** @var Account $account */
    $account = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('gives every dependent row its own tombstone, not only the split legs', function (): void {
    $transaction = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -6521,
        'settled_amount_minor' => -6521,
    ]);

    $categoryId = DB::table('categories')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'name' => 'Takeaway',
        'slug' => 'takeaway',
        'kind' => 'expense',
    ]);

    $seriesId = DB::table('recurring_series')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'direction' => 'expense',
        'detected_name' => 'DOMINOS PIZZA',
        'latest_amount_minor' => -6521,
        'latest_currency' => 'EUR',
        'cluster_key' => 'expense::test::eur::monthly',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);

    // The occurrence is the row that was found stuck in a peer's quarantine as
    // missing_reference: the database cascaded it away without a tombstone, so
    // its create op stayed live and could never be applied again.
    $occurrenceId = DB::table('recurring_series_occurrences')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'recurring_series_id' => $seriesId,
        'transaction_id' => $transaction->id,
        'observed_at' => '2026-05-10',
        'observed_amount_minor' => -6521,
        'observed_currency' => 'EUR',
    ]);

    $legId = DB::table('transaction_splits')->insertGetId([
        'user_id' => $this->fixtureUser->id,
        'transaction_id' => $transaction->id,
        'category_id' => $categoryId,
        'settled_amount_minor' => -6521,
        'settled_currency' => 'EUR',
        'sort_order' => 0,
    ]);

    app(DeletesTransaction::class)->delete($this->fixtureUser, $transaction->id);

    $tombstoned = [];
    Event::assertDispatched(EntityMutated::class, function (EntityMutated $event) use (&$tombstoned): bool {
        if ($event->mutationType === 'delete') {
            $tombstoned[$event->table][] = $event->pk;
        }

        return true;
    });

    expect($tombstoned['recurring_series_occurrences'] ?? [])->toBe([$occurrenceId]);
    expect($tombstoned['transaction_splits'] ?? [])->toBe([$legId]);

    expect(DB::table('recurring_series_occurrences')->where('id', $occurrenceId)->exists())->toBeFalse();
    expect(DB::table('transaction_splits')->where('id', $legId)->exists())->toBeFalse();

    // The series itself is not owned by the transaction and must survive it.
    expect(DB::table('recurring_series')->where('id', $seriesId)->exists())->toBeTrue();
});
