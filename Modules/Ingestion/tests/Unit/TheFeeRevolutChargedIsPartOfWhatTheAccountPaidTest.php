<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// Expectations are read out of the fixture's own Balance column rather than
// written here: Balance is the bank's arithmetic, so a row's settled figure has
// to equal the step the bank took. A hand-written expected number would only
// restate the formula under test.
const REVOLUT_FEE_FIXTURES = __DIR__.'/../fixtures/csv/';

function revolutFeeMinor(string $cell): int
{
    return (new GenericCsvAmountParser)->parseMinor($cell, '.');
}

/**
 * @return list<array<string, string>>
 */
function revolutFeeRows(string $fixture): array
{
    $handle = fopen(REVOLUT_FEE_FIXTURES.$fixture, 'r');
    expect($handle)->not->toBeFalse();

    $header = fgetcsv($handle, 0, ',', '"', '');
    expect($header)->toBeArray();

    $rows = [];
    while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        /** @var array<string, string> $keyed */
        $keyed = array_combine($header, $record);
        $rows[] = $keyed;
    }
    fclose($handle);

    return $rows;
}

/**
 * @return list<SourceTransactionDto>
 */
function revolutFeeParse(string $fixture): array
{
    $registry = new CsvPresetRegistry;
    $preset = $registry->get(CsvPresetRegistry::REVOLUT);
    expect($preset)->not->toBeNull();

    $resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    return iterator_to_array(
        (new GenericCsvAdapter($preset, new GenericCsvAmountParser, new HeaderSniffer))
            ->parse(REVOLUT_FEE_FIXTURES.$fixture, $resolver),
        preserve_keys: false,
    );
}

dataset('revolut fee exports', ['revolut-fee-sample.csv', 'revolut-fee-on-credit.csv']);

it('settles each row for the step the bank took, fee included', function (string $fixture): void {
    $rows = revolutFeeRows($fixture);
    $dtos = revolutFeeParse($fixture);

    expect($dtos)->toHaveCount(count($rows));

    for ($n = 1; $n < count($rows); $n++) {
        $bankStep = revolutFeeMinor($rows[$n]['Balance']) - revolutFeeMinor($rows[$n - 1]['Balance']);
        $settled = $dtos[$n]->settledAmountMinor ?? $dtos[$n]->amountMinor;

        expect($settled)->toBe($bankStep, sprintf(
            '%s row %d (%s): settled %d, bank moved %d',
            $fixture,
            $n,
            $rows[$n]['Description'],
            $settled,
            $bankStep,
        ));
    }
})->with('revolut fee exports');

it('leaves the native amount the merchant charged alone', function (string $fixture): void {
    $rows = revolutFeeRows($fixture);
    $dtos = revolutFeeParse($fixture);

    foreach ($rows as $n => $row) {
        expect($dtos[$n]->amountMinor)->toBe(revolutFeeMinor($row['Amount']));
        expect($dtos[$n]->currency)->toBe($row['Currency']);
    }
})->with('revolut fee exports');

it('shrinks a credit by its fee instead of growing it', function (): void {
    $dtos = revolutFeeParse('revolut-fee-on-credit.csv');

    $refund = $dtos[1];
    expect($refund->amountMinor)->toBe(1200);
    expect($refund->settledAmountMinor)->toBe(1150);

    $topUp = $dtos[2];
    expect($topUp->amountMinor)->toBe(20000);
    expect($topUp->settledAmountMinor)->toBe(19825);
});

it('leaves a fee-free row with no settled figure of its own', function (): void {
    $dtos = revolutFeeParse('revolut-fee-sample.csv');

    expect($dtos[0]->settledAmountMinor)->toBeNull();
    expect($dtos[0]->settledCurrency)->toBeNull();

    expect($dtos[2]->settledAmountMinor)->toBe(-10125);
    expect($dtos[2]->settledCurrency)->toBe('EUR');
});

it('keeps the Fee cell verbatim in the raw payload', function (): void {
    $dtos = revolutFeeParse('revolut-fee-sample.csv');

    expect($dtos[2]->rawPayload['Fee'])->toBe('1.25');
    expect($dtos[2]->rawPayload['Amount'])->toBe('-100.00');
    expect($dtos[2]->rawPayload['Balance'])->toBe('910.75');
});
