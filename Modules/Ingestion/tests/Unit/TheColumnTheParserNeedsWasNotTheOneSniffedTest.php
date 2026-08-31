<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// A column the adapter reads through cell() is not optional — the read throws.
// Leaving it out of the sniff signature moved that refusal past the header
// check, where it arrives as InvalidAmountException: a FileStoppedShort with
// no detail, because only a NamesAFormatMismatch carries its own wording out.
beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };
});

function csvWithoutColumn(string $fixture, string $header): string
{
    $rows = array_filter(explode("\n", (string) file_get_contents(
        base_path('Modules/Ingestion/tests/fixtures/csv/'.$fixture),
    )), static fn (string $line): bool => trim($line) !== '');

    $parsed = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), $rows);
    $drop = array_search($header, $parsed[0], strict: true);
    expect($drop)->not->toBeFalse("fixture {$fixture} must ship a '{$header}' column to drop");

    $out = [];
    foreach ($parsed as $cells) {
        unset($cells[$drop]);
        $out[] = implode(',', array_map(
            static fn (?string $c): string => '"'.str_replace('"', '""', (string) $c).'"',
            array_values($cells),
        ));
    }

    $tmp = tempnam(sys_get_temp_dir(), 'no-column-').'.csv';
    file_put_contents($tmp, implode("\n", $out)."\n");
    register_shutdown_function(static function () use ($tmp): void {
        @unlink($tmp);
    });

    return $tmp;
}

it('refuses at the header check every column the row parser will read', function (
    string $format,
    string $fixture,
    string $header,
): void {
    $registry = new CsvPresetRegistry;
    $preset = $registry->get($format);
    expect($preset)->not->toBeNull();

    $adapter = new GenericCsvAdapter($preset, new GenericCsvAmountParser, new HeaderSniffer);
    $path = csvWithoutColumn($fixture, $header);

    expect(fn () => iterator_to_array($adapter->parse($path, $this->resolver), preserve_keys: false))
        ->toThrow(SniffMismatchException::class, $header);
})->with([
    'ING own-IBAN column' => [CsvPresetRegistry::ING_NL, 'ing-nl-sample.csv', 'Rekening'],
    'ING description column' => [CsvPresetRegistry::ING_NL, 'ing-nl-sample.csv', 'Mededelingen'],
    'N26 value-date column' => [CsvPresetRegistry::N26, 'n26-sample.csv', 'Value Date'],
    'N26 description column' => [CsvPresetRegistry::N26, 'n26-sample.csv', 'Payment Reference'],
]);

// The counterweight: a column read through optionalCell() is genuinely optional
// and must not be promoted into the header check along with the rest.
it('still reads an export that omits a column the row parser only maybe reads', function (): void {
    $registry = new CsvPresetRegistry;
    $preset = $registry->get(CsvPresetRegistry::REVOLUT);
    expect($preset)->not->toBeNull();

    $adapter = new GenericCsvAdapter($preset, new GenericCsvAmountParser, new HeaderSniffer);
    $path = csvWithoutColumn('revolut-sample.csv', 'Fee');

    $dtos = iterator_to_array($adapter->parse($path, $this->resolver), preserve_keys: false);

    expect($dtos)->toHaveCount(2);
    expect($dtos[0]->amountMinor)->toBe(-999);
    expect($dtos[0]->settledAmountMinor)->toBeNull();
});
