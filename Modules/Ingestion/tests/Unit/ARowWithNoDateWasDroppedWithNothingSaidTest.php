<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\HeaderSniffer;

// A CSV carries no opening or closing balance, so nothing downstream can work
// out that a row went missing. The reader dropped any row whose date cell was
// empty -- amount, counterparty and description and all -- and renumbered the
// rows after it over the gap. The positional reader beside it has always
// refused an undated row, through the date parser it has no way around.
const UNDATED_ROW_HEADER = '"Datum","Naam/Omschrijving","Rekening","Tegenrekening","Code","Af Bij","Bedrag (EUR)","MutatieSoort","Mededelingen"';

function undatedRowCsv(string ...$rows): string
{
    return UNDATED_ROW_HEADER."\n".implode("\n", $rows)."\n";
}

function undatedRowLine(string $date, string $amount, string $name): string
{
    return sprintf(
        '"%s","%s","NL57ASNB0123456789","NL91ABNA0417164300","BA","Af","%s","Betaalautomaat","Pasvolgnr 001"',
        $date,
        $name,
        $amount,
    );
}

/**
 * @return list<SourceTransactionDto>
 */
function undatedRowParse(string $csv): array
{
    $registry = new CsvPresetRegistry;
    $preset = $registry->get('ing-nl-csv');
    expect($preset)->not->toBeNull();

    $tmp = tempnam(sys_get_temp_dir(), 'ing-undated-').'.csv';
    file_put_contents($tmp, $csv);

    try {
        return iterator_to_array(
            (new GenericCsvAdapter($preset, new GenericCsvAmountParser, new HeaderSniffer))
                ->parse($tmp, new class implements AccountResolver
                {
                    public function resolve(string $iban): AccountResolution
                    {
                        return AccountResolution::unknown($iban);
                    }
                }),
            preserve_keys: false,
        );
    } finally {
        @unlink($tmp);
    }
}

it('refuses a row that carries an amount but no date, naming the column', function (): void {
    $csv = undatedRowCsv(
        undatedRowLine('20260501', '23,45', 'Albert Heijn'),
        undatedRowLine('', '99,95', 'Undated Merchant'),
        undatedRowLine('20260503', '12,00', 'Bakker'),
    );

    expect(static fn (): array => undatedRowParse($csv))
        ->toThrow(InvalidAmountException::class, 'Datum');
});

it('still skips the empty line an export ends on', function (): void {
    $csv = undatedRowCsv(
        undatedRowLine('20260501', '23,45', 'Albert Heijn'),
        undatedRowLine('20260503', '12,00', 'Bakker'),
        '"","","","","","","","",""',
    );

    $dtos = undatedRowParse($csv);

    expect($dtos)->toHaveCount(2)
        ->and(array_map(static fn (SourceTransactionDto $d): int => $d->amountMinor, $dtos))
        ->toBe([-2345, -1200]);
});

it('reads every row of a file where each one is dated', function (): void {
    $dtos = undatedRowParse(undatedRowCsv(
        undatedRowLine('20260501', '23,45', 'Albert Heijn'),
        undatedRowLine('20260503', '12,00', 'Bakker'),
    ));

    expect(array_map(static fn (SourceTransactionDto $d): int => $d->sourceRowIndex, $dtos))->toBe([0, 1]);
});
