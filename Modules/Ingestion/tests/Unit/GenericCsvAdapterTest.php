<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\HeaderSniffer;

function genericCsvResolver(): AccountResolver
{
    return new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };
}

function genericCsvAdapter(string $format): GenericCsvAdapter
{
    $registry = new CsvPresetRegistry;
    $preset = $registry->get($format);
    expect($preset)->not->toBeNull();

    return new GenericCsvAdapter($preset, new GenericCsvAmountParser, new HeaderSniffer);
}

/**
 * @return list<SourceTransactionDto>
 */
function genericCsvParse(string $format, string $fixture): array
{
    return iterator_to_array(
        genericCsvAdapter($format)->parse(
            base_path('Modules/Ingestion/tests/fixtures/csv/'.$fixture),
            genericCsvResolver(),
        ),
        preserve_keys: false,
    );
}

it('parses the N26 export: signed amount, ISO dates, partner IBAN/name', function (): void {
    $dtos = genericCsvParse('n26-csv', 'n26-sample.csv');

    expect($dtos)->toHaveCount(2);
    expect($dtos[0]->amountMinor)->toBe(-2345);
    expect($dtos[0]->counterpartyName)->toBe('REWE');
    expect($dtos[0]->counterpartyIban)->toBe('DE89370400440532013000');
    expect($dtos[0]->currency)->toBe('EUR');
    expect($dtos[0]->postedAt->toDateString())->toBe('2026-05-01');
    expect($dtos[1]->amountMinor)->toBe(250000);
    // No own-IBAN column → stable synthetic.
    expect($dtos[0]->ownIban)->toBe('N26');
});

it('parses the Revolut export: signed amount, per-row currency, datetime date', function (): void {
    $dtos = genericCsvParse('revolut-csv', 'revolut-sample.csv');

    expect($dtos)->toHaveCount(2);
    expect($dtos[0]->amountMinor)->toBe(-999);
    expect($dtos[0]->currency)->toBe('EUR');
    expect($dtos[0]->description)->toBe('Spotify');
    expect($dtos[0]->postedAt->toDateString())->toBe('2026-05-02');
    expect($dtos[1]->amountMinor)->toBe(10000);
    expect($dtos[0]->ownIban)->toBe('REVOLUT');
});

it('parses the ING NL export: Af/Bij indicator sign, YYYYMMDD date, comma decimal with dot thousands', function (): void {
    $dtos = genericCsvParse('ing-nl-csv', 'ing-nl-sample.csv');

    expect($dtos)->toHaveCount(2);
    // "Af" + "23,45" → outgoing -2345.
    expect($dtos[0]->amountMinor)->toBe(-2345);
    expect($dtos[0]->counterpartyName)->toBe('Albert Heijn');
    expect($dtos[0]->ownIban)->toBe('NL91ABNA0417164300');
    expect($dtos[0]->postedAt->toDateString())->toBe('2026-05-01');
    // "Bij" + "2.500,00" → incoming +250000.
    expect($dtos[1]->amountMinor)->toBe(250000);
    expect($dtos[1]->counterpartyIban)->toBe('DE89370400440532013000');
});

it('rejects a CSV that does not match the declared preset header', function (): void {
    // The Revolut fixture lacks N26's "Booking Date" header.
    expect(fn () => genericCsvParse('n26-csv', 'revolut-sample.csv'))
        ->toThrow(SniffMismatchException::class);
});
