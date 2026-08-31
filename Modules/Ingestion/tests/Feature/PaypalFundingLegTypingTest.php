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

// Going through PaypalCsvAdapter::parse() rather than the classifier alone is
// what makes these cover both PaypalCsvEventTypeMap call sites: the rollup
// walker classifies every row in pass 1, so a missing funding-leg entry throws
// UnknownPaypalEventTypeException before the classifier is ever reached.

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
 * @return string the temp path; caller registers it for afterEach cleanup
 */
function paypalFundingLegFixturePath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'paypal-funding-leg-').'.csv';

    // Column order is the empirical NL Activity Download shape.
    $header = 'Datum,Tijd,Tijdzone,Omschrijving,Valuta,"Bruto ","Kosten ",Netto,Saldo,Transactiereferentie,"Van e-mailadres",Naam,"Naam bank",Bankrekening,Verzendkosten,Btw,Factuurreferentie,"Reference Txn ID"';
    // Bankstorting parent — a positive Bruto top-up into PayPal. The empty
    // Reference Txn ID is what makes the walker treat it as a standalone
    // parent, and the empty counterparty cells match the real export.
    $bankstortingRow = '5/1/2026,09:15:00,Europe/Berlin,"Bankstorting",EUR,"50,00","0,00","50,00","50,00",O-FUNDING0000000000001,,,,,"0,00","0,00",,';
    // Express Checkout purchase parent — negative Bruto (purchase).
    $expressCheckoutRow = '5/2/2026,12:30:00,Europe/Berlin,"Express Checkout-betaling",EUR,"-12,99","0,00","-12,99","37,01",O-PURCHASE000000000001,kaarthouder@example.test,"Example Merchant",,,"0,00","0,00",,';

    file_put_contents($path, $header."\n".$bankstortingRow."\n".$expressCheckoutRow."\n");

    return $path;
}

function paypalFundingLegResolver(): AccountResolver
{
    return new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };
}

/**
 * @param  array<int|string, mixed>  $rawPayload
 */
function paypalFundingLegCanonical(
    SourceTransactionDto $source,
    int $accountId,
    int $userId,
): CanonicalTransaction {
    // Seeded with NormalizeStage's amount-sign default, so what the classifier
    // does to it is the thing under test.
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
    // The null counterpartyIban is the point: the typing has to come from the
    // parent-event-type map, not from a cross-account IBAN match.
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
