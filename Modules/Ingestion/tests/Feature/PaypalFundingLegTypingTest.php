<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Import\Internal\Pipeline\Stages\ClassifyTransactionType;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/*
 * Closes gap-2-paypal-funding-leg-mapping end-to-end.
 *
 * Drives a synthetic PayPal Activity Download CSV containing two parent
 * rows through the FULL ingestion pipeline (HeaderSniffer → adapter →
 * rollup walker → ClassifyTransactionType) and pins the resulting
 * canonical Transaction type for each row:
 *
 *   - A `Bankstorting` parent row (positive Bruto = funding-leg top-up
 *     into PayPal) becomes `transfer_in`. This is the PayPal-side leg
 *     `PairTransferCandidates` matches against the ASN-side
 *     `transfer_out` once the IBAN-alias bridge (Plan 03 / Plan 04)
 *     wires the cross-account hop classifier — but the typing itself
 *     comes purely from `PaypalCsvEventTypeMap`, so this test does
 *     NOT depend on the bridge.
 *
 *   - An `Express Checkout-betaling` parent row remains `expense`
 *     (regression guard against the funding-leg map additions
 *     shadowing the purchase-event arm).
 *
 * Driving via `PaypalCsvAdapter::parse()` exercises the rollup walker
 * which calls `PaypalCsvEventTypeMap::classify()` on every row in
 * Pass 1 — so a missing funding-leg MAP entry would surface as an
 * `UnknownPaypalEventTypeException` at parse time, BEFORE we ever
 * reach the classifier. The test therefore covers BOTH map call
 * sites (classify + transactionType) via a single end-to-end run.
 */

beforeEach(function (): void {
    /** @var array{user: User, account: Account, icsAccount: Account, paypalAccount: Account} $seed */
    $seed = $this->seedFixtureUserAndAccount();
    $this->user = $seed['user'];
    $this->paypalAccount = $seed['paypalAccount'];
    $this->adapter = $this->app->make(PaypalCsvAdapter::class);
    $this->classifier = $this->app->make(ClassifyTransactionType::class);

    /** @var list<string> $tempFiles */
    $this->tempFiles = [];
});

afterEach(function (): void {
    /** @var list<string> $files */
    $files = $this->tempFiles ?? [];
    foreach ($files as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

/**
 * Writes a synthetic PayPal Activity Download CSV with two parent rows:
 * a `Bankstorting` funding-leg parent (positive Bruto) and an
 * `Express Checkout-betaling` purchase parent (negative Bruto).
 *
 * Header columns and ordering match the empirical NL Activity Download
 * fixture at tests/fixtures/paypal/paypal-sample-1.csv. The
 * `Reference Txn ID` cell is empty on both rows, so both are treated as
 * standalone parents by the rollup walker (no children to fold in).
 *
 * @return string the temp path; caller registers it for afterEach cleanup
 */
function paypalFundingLegFixturePath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'paypal-funding-leg-').'.csv';

    $header = 'Datum,Tijd,Tijdzone,Omschrijving,Valuta,"Bruto ","Kosten ",Netto,Saldo,Transactiereferentie,"Van e-mailadres",Naam,"Naam bank",Bankrekening,Verzendkosten,Btw,Factuurreferentie,"Reference Txn ID"';
    // Bankstorting parent — positive Bruto (top-up from a bank account
    // into PayPal). Standalone parent: empty Reference Txn ID. Source
    // counterparty cells stay empty in the empirical NL export.
    $bankstortingRow = '5/1/2026,09:15:00,Europe/Berlin,"Bankstorting",EUR,"50,00","0,00","50,00","50,00",O-FUNDING0000000000001,,,,,"0,00","0,00",,';
    // Express Checkout purchase parent — negative Bruto (purchase).
    $expressCheckoutRow = '5/2/2026,12:30:00,Europe/Berlin,"Express Checkout-betaling",EUR,"-12,99","0,00","-12,99","37,01",O-PURCHASE000000000001,kaarthouder@example.test,"Example Merchant",,,"0,00","0,00",,';

    file_put_contents($path, $header."\n".$bankstortingRow."\n".$expressCheckoutRow."\n");

    return $path;
}

/**
 * AccountResolver double that records every lookup the adapter performs
 * and always returns `unknown()` — the rollup walker only needs the
 * synthetic `PAYPAL` IBAN to be resolved exactly once.
 */
function paypalFundingLegResolver(): AccountResolver
{
    return new class implements AccountResolver
    {
        /** @var list<string> */
        public array $askedFor = [];

        public function resolve(string $iban): AccountResolution
        {
            $this->askedFor[] = $iban;

            return AccountResolution::unknown($iban);
        }
    };
}

/**
 * Builds a CanonicalTransaction from a SourceTransactionDto using the
 * same field-by-field mapping the production NormalizeStage performs
 * for the subset of fields ClassifyTransactionType reads
 * (`accountId`, `type`, `amountMinor`, `counterpartyIban`,
 * `rawPayload`). `type` starts at NormalizeStage's amount-sign
 * default (negative → expense, positive → income) so the classifier's
 * step-3 PayPal event-type map arm is the contract under test.
 *
 * @param  array<int|string, mixed>  $rawPayload
 */
function paypalFundingLegCanonical(
    SourceTransactionDto $source,
    int $accountId,
    int $userId,
): CanonicalTransaction {
    $defaultType = $source->amountMinor < 0 ? 'expense' : 'income';

    return new CanonicalTransaction(
        userId: $userId,
        accountId: $accountId,
        type: $defaultType,
        postedAt: $source->postedAt,
        bookedAt: $source->bookedAt,
        valueDate: $source->valueDate,
        amountMinor: $source->amountMinor,
        currency: $source->currency,
        settledAmountMinor: $source->settledAmountMinor ?? $source->amountMinor,
        settledCurrency: $source->settledCurrency ?? $source->currency,
        fxRateUsed: $source->fxRateUsed,
        counterpartyName: $source->counterpartyName,
        counterpartyIban: $source->counterpartyIban,
        counterpartyNormalized: $source->counterpartyName !== null
            ? mb_strtolower($source->counterpartyName)
            : 'unknown',
        normalizationVersion: 1,
        description: $source->description,
        categoryId: null,
        sourceFormat: 'paypal-csv',
        importRunId: 1,
        sourceRowIndex: $source->sourceRowIndex,
        sourceRef: $source->sourceRef,
        rawPayload: $source->rawPayload,
    );
}

it('classifies a Bankstorting funding-leg parent row as transfer_in end-to-end', function (): void {
    $path = paypalFundingLegFixturePath();
    $this->tempFiles[] = $path;

    $resolver = paypalFundingLegResolver();
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($path, $resolver), false);

    expect($dtos)->toHaveCount(2);

    $bankstorting = null;
    foreach ($dtos as $dto) {
        $events = $dto->rawPayload['events'] ?? [];
        if (is_array($events) && $events !== []) {
            $first = $events[array_key_first($events)] ?? null;
            $type = is_array($first) ? ($first['type'] ?? null) : null;
            if ($type === 'Bankstorting') {
                $bankstorting = $dto;
                break;
            }
        }
    }

    expect($bankstorting)->not->toBeNull();
    /** @var SourceTransactionDto $bankstorting */
    expect($bankstorting->amountMinor)->toBe(5000);
    expect($bankstorting->currency)->toBe('EUR');

    $canonical = paypalFundingLegCanonical(
        $bankstorting,
        accountId: (int) $this->paypalAccount->id,
        userId: (int) $this->user->id,
    );

    $classified = $this->classifier->run($canonical, $this->user);

    expect($classified->type)->toBe('transfer_in');
})->group('phase-16.1.2.1');

it('keeps an Express Checkout-betaling purchase parent as expense (regression guard)', function (): void {
    $path = paypalFundingLegFixturePath();
    $this->tempFiles[] = $path;

    $resolver = paypalFundingLegResolver();
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($path, $resolver), false);

    $expressCheckout = null;
    foreach ($dtos as $dto) {
        $events = $dto->rawPayload['events'] ?? [];
        if (is_array($events) && $events !== []) {
            $first = $events[array_key_first($events)] ?? null;
            $type = is_array($first) ? ($first['type'] ?? null) : null;
            if ($type === 'Express Checkout-betaling') {
                $expressCheckout = $dto;
                break;
            }
        }
    }

    expect($expressCheckout)->not->toBeNull();
    /** @var SourceTransactionDto $expressCheckout */
    expect($expressCheckout->amountMinor)->toBe(-1299);

    $canonical = paypalFundingLegCanonical(
        $expressCheckout,
        accountId: (int) $this->paypalAccount->id,
        userId: (int) $this->user->id,
    );

    $classified = $this->classifier->run($canonical, $this->user);

    expect($classified->type)->toBe('expense');
})->group('phase-16.1.2.1');

it('does not depend on a counterparty IBAN to type the funding leg (IBAN-alias bridge not required)', function (): void {
    // Repeat the Bankstorting assertion with explicit confirmation that
    // counterpartyIban is null on the canonical row — the typing comes
    // purely from the parent-event-type map, NOT from a cross-account
    // IBAN match (which would be the Plan 03 / Plan 04 alias-bridge
    // path).
    $path = paypalFundingLegFixturePath();
    $this->tempFiles[] = $path;

    $resolver = paypalFundingLegResolver();
    /** @var list<SourceTransactionDto> $dtos */
    $dtos = iterator_to_array($this->adapter->parse($path, $resolver), false);

    $bankstorting = null;
    foreach ($dtos as $dto) {
        $events = $dto->rawPayload['events'] ?? [];
        if (is_array($events) && $events !== []) {
            $first = $events[array_key_first($events)] ?? null;
            $type = is_array($first) ? ($first['type'] ?? null) : null;
            if ($type === 'Bankstorting') {
                $bankstorting = $dto;
                break;
            }
        }
    }

    expect($bankstorting)->not->toBeNull();
    /** @var SourceTransactionDto $bankstorting */
    expect($bankstorting->counterpartyIban)->toBeNull();

    $canonical = paypalFundingLegCanonical(
        $bankstorting,
        accountId: (int) $this->paypalAccount->id,
        userId: (int) $this->user->id,
    );

    expect($canonical->counterpartyIban)->toBeNull();

    $classified = $this->classifier->run($canonical, $this->user);

    expect($classified->type)->toBe('transfer_in');
})->group('phase-16.1.2.1');
