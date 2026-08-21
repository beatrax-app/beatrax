<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->adapter = $this->app->make(Mt940Adapter::class);
});

it('declares the mt940 format identifier', function (): void {
    expect($this->adapter->format())->toBe('mt940');
})->group('phase-2');

it('registers under the mt940 key in the SourceAdapterRegistry', function (): void {
    /** @var SourceAdapterRegistry $registry */
    $registry = $this->app->make(SourceAdapterRegistry::class);
    $adapter = $registry->for('mt940');

    expect($adapter)->toBeInstanceOf(Mt940Adapter::class);
    expect($adapter->format())->toBe('mt940');
})->group('phase-2');

it('parses the anonymised MT940 fixture into SourceTransactionDto rows', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-mt940-sample-1.sta'), $this->resolver),
        preserve_keys: false,
    );

    expect($dtos)->toHaveCount(12);
    foreach ($dtos as $dto) {
        expect($dto)->toBeInstanceOf(SourceTransactionDto::class);
        expect($dto->ownIban)->toBe('NL57ASNB0123456789');
        expect($dto->currency)->toBe('EUR');
        expect($dto->amountMinor)->toBeInt();
        expect($dto->amountMinor)->not->toBe(0);
        expect($dto->sourceRowIndex)->toBeInt();
        expect($dto->rawPayload)->toBeArray();
        expect($dto->rawPayload)->toHaveKey('mt940');
    }
})->group('phase-2');

it('matches the snapshot of the parsed MT940 fixture (drift detector)', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-mt940-sample-1.sta'), $this->resolver),
        preserve_keys: false,
    );

    $serialized = array_map(static fn (SourceTransactionDto $d): array => [
        'bookedAt' => $d->bookedAt->format('Y-m-d H:i:s'),
        'valueDate' => $d->valueDate->format('Y-m-d'),
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
})->group('phase-2');

it('pairs each :61: with the immediately following :86:', function (): void {
    $body = ":20:STMT-001\n:25:NL57ASNB0123456789\n:28C:1/1\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFINV-001\n:86:100?20EREF+INV-001?32SPOTIFY AB?31NL68BANK0000000001\n"
        .":61:2604020402D50,29NMSCFEE\n:86:Unstructured fee description\n"
        .":62F:C260430EUR1049,71\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);

    expect($dtos)->toHaveCount(2);
    expect($dtos[0]->counterpartyName)->toBe('SPOTIFY AB');
    expect($dtos[0]->sourceRef)->toBe('INV-001');
    expect($dtos[0]->amountMinor)->toBe(10000);
    expect($dtos[1]->counterpartyName)->toBeNull();
    expect($dtos[1]->amountMinor)->toBe(-5029);
})->group('phase-2');

it('flushes a trailing :61: without a following :86: as a row with no narrative', function (): void {
    $body = ":20:STMT-001\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFINV-001\n"
        .":62F:C260430EUR1100,00\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->counterpartyName)->toBeNull();
    expect($dtos[0]->sourceRef)->toBe('INV-001');
})->group('phase-2');

it('treats EREF=NOTPROVIDED as null sourceRef and falls back to the :61: customer reference', function (): void {
    $body = ":20:S\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFCUSTREF\n:86:100?20EREF+NOTPROVIDED?32X\n"
        .":62F:C260430EUR1100,00\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);

    expect($dtos[0]->sourceRef)->toBe('CUSTREF');
})->group('phase-2');

it('captures statement metadata for the writer (period, balances, entry count)', function (): void {
    iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-mt940-sample-1.sta'), $this->resolver),
        preserve_keys: false,
    );
    $meta = $this->adapter->statementMetadata();

    expect($meta)->not->toBeNull();
    expect($meta->ibanOwner)->toBe('NL57ASNB0123456789');
    expect($meta->openingBalanceMinor)->toBeInt();
    expect($meta->closingBalanceMinor)->toBeInt();
    expect($meta->openingBalanceCurrency)->toBe('EUR');
    expect($meta->entryCount)->toBeGreaterThan(0);
})->group('phase-2');

it('cleans the counterparty name via Mt940CounterpartyCleaner before yielding', function (): void {
    $body = ":20:S\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFCUST\n:86:100?32005 SPOTIFY AB ABNANL2A?31NL68BANK0000000001\n"
        .":62F:C260430EUR1100,00\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);

    expect($dtos[0]->counterpartyName)->toBe('SPOTIFY AB');
})->group('phase-2');

it('flags multi-statement files in statementMetadata extras', function (): void {
    $body = ":20:STMT-A\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFINV-001\n:86:100?32X\n"
        .":62F:C260430EUR1100,00\n-\n"
        .":20:STMT-B\n:25:NL57ASNB0123456789\n:60F:C260501EUR1100,00\n"
        .":61:2605010501C200,00NTRFINV-002\n:86:100?32Y\n"
        .":62F:C260531EUR1300,00\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);
    $meta = $this->adapter->statementMetadata();

    expect($dtos)->toHaveCount(2);
    expect($meta)->not->toBeNull();
    expect($meta->extras)->toHaveKey('multiStatement');
    expect($meta->extras['multiStatement'])->toBeTrue();
})->group('phase-2');

it('pins entryCount to the first statement on multi-statement files', function (): void {
    // Three entries across two statements, but the metadata snapshot pins to
    // statement A, which has two of them.
    $body = ":20:STMT-A\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFA-1\n:86:100?32X\n"
        .":61:2604020402D50,00NTRFA-2\n:86:100?32Y\n"
        .":62F:C260430EUR1050,00\n-\n"
        .":20:STMT-B\n:25:NL57ASNB0123456789\n:60F:C260501EUR1050,00\n"
        .":61:2605010501C200,00NTRFB-1\n:86:100?32Z\n"
        .":62F:C260531EUR1250,00\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);
    $meta = $this->adapter->statementMetadata();

    expect($dtos)->toHaveCount(3);
    expect($meta)->not->toBeNull();
    expect($meta->entryCount)->toBe(2);
})->group('phase-2');

it('rejects a :61: that arrives before :25: (own IBAN missing)', function (): void {
    // :60F: comes first so the currency check passes and only the missing IBAN
    // can be what fails.
    $body = ":20:STMT-NO-IBAN\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFINV-001\n:86:100?32X\n"
        .":62F:C260430EUR1100,00\n-\n";
    $tmp = writeMt940Temp($body);

    try {
        expect(fn () => iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false))
            ->toThrow(InvalidAmountException::class, 'before :25:');
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('rejects a :61: that arrives before any balance tag has set a currency', function (): void {
    // :25: comes first so the IBAN check passes and only the missing currency
    // can be what fails.
    $body = ":20:STMT-NO-CCY\n:25:NL57ASNB0123456789\n"
        .":61:2604010401C100,00NTRFINV-001\n:86:100?32X\n"
        .":62F:C260430EUR1100,00\n-\n";
    $tmp = writeMt940Temp($body);

    try {
        expect(fn () => iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false))
            ->toThrow(InvalidAmountException::class, 'before any balance tag');
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('rejects a CSV body in a .sta file at the signature stage', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'wrong-').'.sta';
    file_put_contents($tmp, "Datum,Je rekening\n01-04-2026,NL57ASNB0123456789\n");

    try {
        expect(fn () => iterator_to_array($this->adapter->parse($tmp, $this->resolver)))
            ->toThrow(SniffMismatchException::class);
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('normalises bookedAt to 00:00:00 so cross-format dedup with CSV and CAMT survives', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-mt940-sample-1.sta'), $this->resolver),
        preserve_keys: false,
    );

    foreach ($dtos as $dto) {
        expect($dto->bookedAt->format('H:i:s'))->toBe('00:00:00');
    }
})->group('phase-2');

it('emits monotonically increasing sourceRowIndex starting at zero', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-mt940-sample-1.sta'), $this->resolver),
        preserve_keys: false,
    );

    $expectedIndex = 0;
    foreach ($dtos as $dto) {
        expect($dto->sourceRowIndex)->toBe($expectedIndex);
        $expectedIndex++;
    }
})->group('phase-2');

it('flushes a mid-stream :61: with no :86: when the next :61: arrives', function (): void {
    $body = ":20:S\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFA-1\n"
        .":61:2604020402C200,00NTRFA-2\n"
        .":62F:C260430EUR1300,00\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);

    expect($dtos)->toHaveCount(2);
    expect($dtos[0]->counterpartyName)->toBeNull();
    expect($dtos[0]->sourceRef)->toBe('A-1');
    expect($dtos[1]->sourceRef)->toBe('A-2');
    expect($dtos[0]->sourceRowIndex)->toBe(0);
    expect($dtos[1]->sourceRowIndex)->toBe(1);
})->group('phase-2');

it('ignores a :86: narrative that is not preceded by a :61: entry', function (): void {
    $body = ":20:S\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":86:orphan narrative with no entry\n"
        .":61:2604010401C100,00NTRFINV-001\n:86:100?32SPOTIFY AB\n"
        .":62F:C260430EUR1100,00\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->counterpartyName)->toBe('SPOTIFY AB');
})->group('phase-2');

it('ignores unrecognised MT940 tags without affecting the parsed rows', function (): void {
    // :64: is available balance — a real MT940 tag the ledger has no use for.
    $body = ":20:S\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFINV-001\n:86:100?32SPOTIFY AB\n"
        .":64:C260430EUR1100,00\n"
        .":62F:C260430EUR1100,00\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->sourceRef)->toBe('INV-001');
})->group('phase-2');

it('leaves the closing balance null when its amount cell is malformed', function (): void {
    // The stray extra `,00` group makes BankAmountParser reject the :62F:
    // amount, which must cost the balance and not the whole statement.
    $body = ":20:S\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFINV-001\n:86:100?32SPOTIFY AB\n"
        .":62F:C260430EUR1100,00,00\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);
    $meta = $this->adapter->statementMetadata();

    expect($dtos)->toHaveCount(1);
    expect($meta)->not->toBeNull();
    expect($meta->closingBalanceMinor)->toBeNull();
    expect($meta->openingBalanceMinor)->toBe(100000);
})->group('phase-2');

it('reports null statement metadata when the own IBAN tag is absent', function (): void {
    $body = ":20:STMT-ONLY\n:60F:C260401EUR1000,00\n:62F:C260430EUR1000,00\n-\n";
    $tmp = writeMt940Temp($body);

    $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);

    expect($dtos)->toBe([]);
    expect($this->adapter->statementMetadata())->toBeNull();
})->group('phase-2');
