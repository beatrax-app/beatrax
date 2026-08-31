<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Csv\PositionalCsvAdapter;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\PositionalCsvPreset;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Exceptions\UnsupportedFormatException;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->adapter = $this->app->make(SourceAdapterRegistry::class)->for(CsvPresetRegistry::ASN);
});

it('reports its stable format identifier', function (): void {
    expect($this->adapter->format())->toBe(CsvPresetRegistry::ASN);
});

it('parses the real fixture into SourceTransactionDtos', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    expect($dtos)->toHaveCount(229);
    expect($dtos[0])->toBeInstanceOf(SourceTransactionDto::class);

    foreach ($dtos as $dto) {
        expect($dto->amountMinor)->toBeInt();
        expect($dto->currency)->toBe('EUR');
        expect($dto->ownIban)->toBe('NL57ASNB0123456789');
        expect($dto->sourceRowIndex)->toBeInt();
        expect($dto->rawPayload)->toBeArray();
    }
});

it('decodes the first row column-by-column against the empirical layout', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    $first = $dtos[0];

    expect($first->postedAt->toDateString())->toBe('2026-02-02');
    expect($first->valueDate->toDateString())->toBe('2026-02-02');
    expect($first->ownIban)->toBe('NL57ASNB0123456789');
    expect($first->counterpartyIban)->toBe('NL68BANK0000000001');
    expect($first->counterpartyName)->toBe('KPN Mobiel');
    expect($first->amountMinor)->toBe(-399);
    expect($first->currency)->toBe('EUR');
    expect($first->sourceRef)->toBe('898406');
    expect($first->sourceRowIndex)->toBe(0);
    expect($first->description)->toContain('Europese incasso');
});

it('preserves the full source row in rawPayload for audit', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    foreach ($dtos as $dto) {
        expect($dto->rawPayload)->toHaveCount(20);
        foreach ($dto->rawPayload as $cell) {
            expect($cell)->toBeString();
        }
    }
});

it('emits monotonically increasing sourceRowIndex starting at zero', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    $expectedIndex = 0;
    foreach ($dtos as $dto) {
        expect($dto->sourceRowIndex)->toBe($expectedIndex);
        $expectedIndex++;
    }
});

it('handles UTF-8 diacritics in counterparty names without mojibake', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    $found = false;
    foreach ($dtos as $dto) {
        if ($dto->counterpartyName !== null
            && preg_match('/[\x{00C0}-\x{017F}]/u', $dto->counterpartyName) === 1) {
            $found = true;
            // `Ã` is what a two-byte Unicode character looks like read as latin1.
            expect($dto->counterpartyName)->not->toContain('Ã');
            break;
        }
    }

    expect($found)->toBeTrue(
        'Fixture must include at least one diacritic-bearing counterparty name '
        .'(e.g. "Café Plein") to exercise the UTF-8 round-trip.'
    );
});

it('leaves counterpartyName null when the source row has no name', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    // The fixture's BEA rows have an empty Naam cell. They must arrive here as
    // null, because substituting the `_no_counterparty` sentinel is
    // NormalizeStage's job, not the adapter's.
    $foundNullName = false;
    foreach ($dtos as $dto) {
        if ($dto->counterpartyName === null) {
            $foundNullName = true;
            break;
        }
    }
    expect($foundNullName)->toBeTrue();
});

it('throws InvalidAmountException with the row index for malformed amount cells', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'asn-bad-').'.csv';
    $header = implode(',', [
        'Datum', 'Je rekening', 'Van / naar', 'Naam', 'Adres', 'Postcode', 'Woonplaats',
        'Valuta saldo', 'Saldo voor boeking', 'Valuta', 'Bedrag bij / af',
        'Verwerkingsdatum', 'Valutadatum', 'Code', 'Type', 'Volgnummer',
        'Betalingskenmerk', 'Omschrijving', 'Afschriftnummer', 'Categorie',
    ]);
    $badRow = '01-02-2026,NL57ASNB0123456789,NL41BANK0000000099,Foo,,,,EUR,0.00,EUR,NOT-AN-AMOUNT,01-02-2026,01-02-2026,9999,XXX,1,,Desc,1,';
    file_put_contents($tmp, $header."\n".$badRow."\n");

    try {
        expect(function () use ($tmp): void {
            iterator_to_array(
                $this->adapter->parse($tmp, $this->resolver),
                preserve_keys: false,
            );
        })->toThrow(InvalidAmountException::class, 'Row 0');
    } finally {
        @unlink($tmp);
    }
});

it('rejects a file that fails the header sniffer before reading any data row', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'asn-wrong-').'.csv';
    file_put_contents($tmp, "a,b,c\n1,2,3\n");

    try {
        expect(function () use ($tmp): void {
            iterator_to_array(
                $this->adapter->parse($tmp, $this->resolver),
                preserve_keys: false,
            );
        })->toThrow(SniffMismatchException::class);
    } finally {
        @unlink($tmp);
    }
});

it('parses the 19-column ASN variant (no trailing Categorie column) without errors', function (): void {
    // Some real ASN accounts export without the trailing `Categorie` column,
    // reproduced here by stripping it off the gold 20-column fixture.
    $body = file_get_contents(base_path('tests/fixtures/asn-sample-1.csv'));
    expect($body)->toBeString();
    /** @var string $body */
    $lines = explode("\n", $body);
    foreach ($lines as $i => $line) {
        if ($line === '') {
            continue;
        }
        $lastComma = strrpos($line, ',');
        if ($lastComma !== false) {
            $lines[$i] = substr($line, 0, $lastComma);
        }
    }
    $tmp = tempnam(sys_get_temp_dir(), 'asn-19col-').'.csv';
    file_put_contents($tmp, implode("\n", $lines));

    try {
        $dtos = iterator_to_array(
            $this->adapter->parse($tmp, $this->resolver),
            preserve_keys: false,
        );

        expect($dtos)->toHaveCount(229);
        expect($dtos[0])->toBeInstanceOf(SourceTransactionDto::class);
        expect($dtos[0]->ownIban)->toBe('NL57ASNB0123456789');
        expect($dtos[0]->amountMinor)->toBe(-399);
        expect($dtos[0]->rawPayload)->toHaveCount(19);
    } finally {
        @unlink($tmp);
    }
});

it('carries the empirical column layout as preset data rather than in the adapter', function (): void {
    $preset = $this->app->make(CsvPresetRegistry::class)->positional(CsvPresetRegistry::ASN);

    expect($preset)->not->toBeNull();
    /** @var PositionalCsvPreset $preset */
    expect($preset->postedDateColumn)->toBe(0);
    expect($preset->ownIbanColumn)->toBe(1);
    expect($preset->counterpartyIbanColumn)->toBe(2);
    expect($preset->counterpartyNameColumn)->toBe(3);
    expect($preset->currencyColumn)->toBe(9);
    expect($preset->amountColumn)->toBe(10);
    expect($preset->valueDateColumn)->toBe(12);
    expect($preset->sourceRefColumn)->toBe(15);
    expect($preset->descriptionColumns)->toBe([16, 17]);
    expect($preset->acceptedColumnCounts)->toBe([19, 20]);
    expect($preset->dateFormat)->toBe('d-m-Y');
});

it('keeps the issuer name as preset data and out of every identifier', function (): void {
    $preset = $this->app->make(CsvPresetRegistry::class)->positional(CsvPresetRegistry::ASN);

    expect($preset)->not->toBeNull();
    /** @var PositionalCsvPreset $preset */
    expect($preset->label)->toBe('ASN');
    expect(array_column(SourceFormat::cases(), 'value'))->not->toContain(CsvPresetRegistry::ASN);
    expect(SourceFormat::tryFrom(CsvPresetRegistry::ASN))->toBeNull();
});

it('matches the snapshot of the parsed fixture (drift detector)', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    $serialized = array_map(static fn (SourceTransactionDto $d): array => [
        'postedAt' => $d->postedAt->toDateString(),
        'valueDate' => $d->valueDate->toDateString(),
        'ownIban' => $d->ownIban,
        'counterpartyIban' => $d->counterpartyIban,
        'counterpartyName' => $d->counterpartyName,
        'currency' => $d->currency,
        'amountMinor' => $d->amountMinor,
        'sourceRef' => $d->sourceRef,
        'description' => $d->description,
        'sourceRowIndex' => $d->sourceRowIndex,
    ], $dtos);

    expect($serialized)->toMatchSnapshot();
});

it('registers under the asn-csv key in the SourceAdapterRegistry', function (): void {
    /** @var SourceAdapterRegistry $registry */
    $registry = $this->app->make(SourceAdapterRegistry::class);

    $adapter = $registry->for(CsvPresetRegistry::ASN);
    expect($adapter)->toBeInstanceOf(PositionalCsvAdapter::class);
    expect($adapter->format())->toBe('asn-csv');

    expect(fn () => $registry->for('asn-no-such-format'))
        ->toThrow(UnsupportedFormatException::class);
});
