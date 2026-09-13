<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\SpendByCategoryQuery;
use Modules\Ledger\Public\Services\SplitSumHealthCheck;

uses(RefreshDatabase::class);

// SaveTransactionSplit writes every leg in its parent's currency, and the
// roll-ups trusted it: they asked whether the legs summed to the parent by
// adding the legs' minor units whatever currency each was in. The applier is a
// second writer -- a peer's SET on settled_currency is not gated -- so a EUR
// 50.00 charge could carry one USD leg of 5000, read as balanced, drop out of
// the euro roll-up and arrive in the dollar one under the leg's category.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->alienLegDb = $db;

    $this->alienLegUser = User::create([
        'username' => 'alien-leg-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->alienLegUser);

    $this->alienLegAccount = Account::create([
        'user_id' => $this->alienLegUser->id,
        'name' => 'ASN',
        'slug' => 'alien-leg-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.random_int(1000000000, 9999999999),
        'default_currency' => 'EUR',
    ]);

    $this->alienLegRun = ImportRun::create([
        'user_id' => $this->alienLegUser->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/alien-leg-'.bin2hex(random_bytes(4)).'.xml',
        'sha256' => hash('sha256', 'alien-leg-'.bin2hex(random_bytes(4))),
        'uploaded_at' => CarbonImmutable::parse('2026-07-02 09:00:00'),
        'status' => 'committed',
    ]);

    $this->alienLegRent = Category::create(['user_id' => null, 'name' => 'Rent', 'slug' => 'alien-leg-rent-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 1]);
    $this->alienLegTravel = Category::create(['user_id' => null, 'name' => 'Travel', 'slug' => 'alien-leg-travel-'.bin2hex(random_bytes(4)), 'kind' => 'expense', 'display_order' => 2]);

    $this->alienLegPeriod = new Period(
        start: CarbonImmutable::parse('2026-07-01'),
        endExclusive: CarbonImmutable::parse('2026-08-01'),
        label: 'July 2026',
    );

    $this->alienLegCharge = function (int $settledMinor, int $categoryId): int {
        /** @var DatabaseManager $db */
        $db = $this->alienLegDb;
        $suffix = bin2hex(random_bytes(8));

        return (int) $db->connection()->table('transactions')->insertGetId([
            'user_id' => $this->alienLegUser->id,
            'account_id' => $this->alienLegAccount->id,
            'import_run_id' => $this->alienLegRun->id,
            'category_id' => $categoryId,
            'type' => 'expense',
            'posted_at' => '2026-07-14',
            'booked_at' => '2026-07-14 10:00:00',
            'value_date' => '2026-07-14',
            'amount_minor' => $settledMinor,
            'currency' => 'EUR',
            'settled_amount_minor' => $settledMinor,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Alien Leg Ltd',
            'counterparty_normalized' => 'alien-leg-ltd',
            'normalization_version' => 1,
            'source_format' => 'camt053',
            'source_row_index' => 1,
            'fingerprint' => hash('sha256', 'alien-leg-tx-'.$suffix),
            'fingerprint_version' => 1,
            'created_at' => '2026-07-14 10:00:00',
            'updated_at' => '2026-07-14 10:00:00',
        ]);
    };

    // Straight to the table, because this is the shape no local write path can
    // produce: the leg arrives priced elsewhere from a peer that merged its
    // settled_currency on its own.
    $this->alienLegLeg = function (int $transactionId, int $categoryId, int $settledMinor, string $currency): void {
        /** @var DatabaseManager $db */
        $db = $this->alienLegDb;
        $db->connection()->table('transaction_splits')->insert([
            'user_id' => $this->alienLegUser->id,
            'transaction_id' => $transactionId,
            'category_id' => $categoryId,
            'settled_amount_minor' => $settledMinor,
            'settled_currency' => $currency,
            'note' => null,
            'sort_order' => 0,
            'created_at' => '2026-07-14 10:00:00',
            'updated_at' => '2026-07-14 10:00:00',
        ]);
    };
});

it('rolls a charge up in the currency it settled in when its only leg is priced in another', function (): void {
    $transactionId = ($this->alienLegCharge)(-5000, $this->alienLegRent->id);
    ($this->alienLegLeg)($transactionId, $this->alienLegTravel->id, -5000, 'USD');

    $spend = app(SpendByCategoryQuery::class)
        ->forUserAndPeriodByCurrency((int) $this->alienLegUser->id, $this->alienLegPeriod);

    expect($spend)->toBe([$this->alienLegRent->id.'|EUR' => 5000]);
});

it('keeps the same answer when the period is walked day by day', function (): void {
    $transactionId = ($this->alienLegCharge)(-5000, $this->alienLegRent->id);
    ($this->alienLegLeg)($transactionId, $this->alienLegTravel->id, -5000, 'USD');

    $byDay = app(SpendByCategoryQuery::class)
        ->forUserAndSpanByCurrencyPerDay((int) $this->alienLegUser->id, $this->alienLegPeriod);

    expect($byDay)->toBe(['2026-07-14' => [$this->alienLegRent->id.'|EUR' => 5000]]);
});

it('names the charge as one whose legs no longer add up', function (): void {
    $transactionId = ($this->alienLegCharge)(-5000, $this->alienLegRent->id);
    ($this->alienLegLeg)($transactionId, $this->alienLegTravel->id, -5000, 'USD');

    $check = app(SplitSumHealthCheck::class);

    expect($check->severity())->toBe('warning')
        ->and($check->message())->toContain((string) $transactionId);
});

// The control the change is measured against: a split whose legs are in the
// parent's own currency still rolls up through its legs, so the currency
// clause narrowed what it had to and nothing else.
it('still counts the legs of a split priced in the parent currency', function (): void {
    $transactionId = ($this->alienLegCharge)(-8000, $this->alienLegRent->id);
    ($this->alienLegLeg)($transactionId, $this->alienLegTravel->id, -3000, 'EUR');
    ($this->alienLegLeg)($transactionId, $this->alienLegRent->id, -5000, 'EUR');

    $spend = app(SpendByCategoryQuery::class)
        ->forUserAndPeriodByCurrency((int) $this->alienLegUser->id, $this->alienLegPeriod);

    expect($spend)->toBe([
        $this->alienLegRent->id.'|EUR' => 5000,
        $this->alienLegTravel->id.'|EUR' => 3000,
    ]);

    expect(app(SplitSumHealthCheck::class)->severity())->toBe('ok');
});
