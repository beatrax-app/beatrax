<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Resolution runs once per imported row, so what matters is that the row after
// the ten-thousandth costs what the first did. The country read behind the two
// national tiers is already held for the length of one call; nothing else here
// may start growing with the ledger.

beforeEach(function (): void {
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'resolver-cost-reader',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->user = $user;

    DB::table('accounts')->insert([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'rcr-asn',
        'kind' => 'bank',
        'iban' => 'NL00ASNBRCR00001',
        'default_currency' => 'EUR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->rcrTransaction = function (int $index): CanonicalTransaction {
        return new CanonicalTransaction(
            userId: (int) $this->user->id,
            accountId: 1,
            type: 'expense',
            postedAt: CarbonImmutable::parse('2026-03-01'),
            bookedAt: CarbonImmutable::parse('2026-03-01 00:00:00'),
            valueDate: CarbonImmutable::parse('2026-03-01'),
            amountMinor: -1000 - $index,
            currency: 'EUR',
            settledAmountMinor: -1000 - $index,
            settledCurrency: 'EUR',
            fxRateUsed: null,
            counterpartyName: 'Merchant '.$index,
            counterpartyIban: 'NL00RABO'.str_pad((string) $index, 10, '0', STR_PAD_LEFT),
            counterpartyNormalized: 'rcr-'.$index,
            normalizationVersion: 1,
            description: 'RCR PURCHASE '.$index,
            categoryId: null,
            sourceFormat: 'asn-csv',
            importRunId: 1,
            sourceRowIndex: $index,
            sourceRef: 'rcr:'.$index,
        );
    };
});

// The corpus and the reader's alias list are loaded once behind their own
// services, so the row measured here is a warmed one — which is the row an
// import spends nearly all of its time on.
$rcrStatementsForOne = static function (CounterpartyResolver $resolver, CanonicalTransaction $tx, User $user): int {
    $statements = 0;
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements++;
    });

    $resolver->resolve($tx, $user);

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $statements;
};

it('costs the same reads on the hundredth row as on the fiftieth', function () use ($rcrStatementsForOne): void {
    $resolver = app(CounterpartyResolver::class);

    for ($i = 0; $i < 50; $i++) {
        $resolver->resolve(($this->rcrTransaction)($i), $this->user);
    }

    $fiftieth = $rcrStatementsForOne($resolver, ($this->rcrTransaction)(50), $this->user);

    for ($i = 51; $i < 99; $i++) {
        $resolver->resolve(($this->rcrTransaction)($i), $this->user);
    }

    $hundredth = $rcrStatementsForOne($resolver, ($this->rcrTransaction)(99), $this->user);

    expect($fiftieth)->toBe(7)
        ->and($hundredth)->toBe($fiftieth);
});

// Two services each held their own copy of the answer, so the reader's country
// was read twice for every row of an import. The memo moved to the seam that
// owns the value and both copies went, which is why this is 1 and not 2.
it('reads the readers country once for the whole row, not once per service that asks', function (): void {
    $resolver = app(CounterpartyResolver::class);

    $reads = 0;
    DB::listen(static function (QueryExecuted $query) use (&$reads): void {
        if (str_contains($query->sql, 'select "country_code" from "users"')) {
            $reads++;
        }
    });

    $resolver->resolve(($this->rcrTransaction)(0), $this->user);

    expect($reads)->toBe(1);
});

it('resolves the same row to the same counterparty whatever came before it', function (): void {
    $resolver = app(CounterpartyResolver::class);

    $first = $resolver->resolve(($this->rcrTransaction)(7), $this->user);

    for ($i = 100; $i < 130; $i++) {
        $resolver->resolve(($this->rcrTransaction)($i), $this->user);
    }

    $again = $resolver->resolve(($this->rcrTransaction)(7), $this->user);

    expect($again?->counterpartyId)->toBe($first?->counterpartyId)
        ->and($again?->slug)->toBe($first?->slug)
        ->and($again?->type)->toBe($first?->type)
        ->and($again?->displayName)->toBe($first?->displayName);
});

it('still routes a payment to one of the reader s own accounts to the self tier', function (): void {
    $resolver = app(CounterpartyResolver::class);

    $tx = ($this->rcrTransaction)(0);
    $selfTx = new CanonicalTransaction(
        userId: $tx->userId,
        accountId: $tx->accountId,
        type: 'transfer_out',
        postedAt: $tx->postedAt,
        bookedAt: $tx->bookedAt,
        valueDate: $tx->valueDate,
        amountMinor: $tx->amountMinor,
        currency: $tx->currency,
        settledAmountMinor: $tx->settledAmountMinor,
        settledCurrency: $tx->settledCurrency,
        fxRateUsed: null,
        counterpartyName: 'My Own Savings',
        counterpartyIban: 'nl00 asnb rcr0 0001',
        counterpartyNormalized: $tx->counterpartyNormalized,
        normalizationVersion: $tx->normalizationVersion,
        description: $tx->description,
        categoryId: null,
        sourceFormat: $tx->sourceFormat,
        importRunId: $tx->importRunId,
        sourceRowIndex: $tx->sourceRowIndex,
        sourceRef: $tx->sourceRef,
    );

    $resolved = $resolver->resolve($selfTx, $this->user);

    expect($resolved?->type)->toBe(CounterpartyType::SelfAccount->value)
        ->and($resolved?->iban)->toBe('NL00ASNBRCR00001');
});
