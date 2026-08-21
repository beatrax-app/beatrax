<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
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

    $this->adapter = $this->app->make(Camt053Adapter::class);
});

it('reports the camt053 format identifier', function (): void {
    expect($this->adapter->format())->toBe('camt053');
})->group('phase-2');

it('registers under the camt053 key in the SourceAdapterRegistry', function (): void {
    /** @var SourceAdapterRegistry $registry */
    $registry = $this->app->make(SourceAdapterRegistry::class);

    $adapter = $registry->for('camt053');
    expect($adapter)->toBeInstanceOf(Camt053Adapter::class);
    expect($adapter->format())->toBe('camt053');
})->group('phase-2');

it('parses the anonymised CAMT.053 fixture into SourceTransactionDto rows', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-camt053-sample-1.xml'), $this->resolver),
        preserve_keys: false,
    );

    expect($dtos)->toHaveCount(229);
    foreach ($dtos as $dto) {
        expect($dto)->toBeInstanceOf(SourceTransactionDto::class);
        expect($dto->ownIban)->toBe('NL57ASNB0123456789');
        expect($dto->currency)->toBe('EUR');
        expect($dto->amountMinor)->toBeInt();
        expect($dto->amountMinor)->not->toBe(0);
        expect($dto->sourceRowIndex)->toBeInt();
        expect($dto->rawPayload)->toBeArray();
        expect($dto->rawPayload)->toHaveKey('sepa');
    }
})->group('phase-2');

it('matches the snapshot of the parsed CAMT.053 fixture (drift detector)', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-camt053-sample-1.xml'), $this->resolver),
        preserve_keys: false,
    );

    $serialized = array_map(static fn (SourceTransactionDto $d): array => [
        'bookedAt' => $d->bookedAt->format('Y-m-d H:i:s'),
        'postedAt' => $d->postedAt->format('Y-m-d'),
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

it('populates rawPayload[sepa] with the secondary SEPA refs', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-camt053-sample-1.xml'), $this->resolver),
        preserve_keys: false,
    );

    foreach ($dtos as $dto) {
        expect($dto->rawPayload)->toHaveKey('sepa');
        $sepa = $dto->rawPayload['sepa'];
        expect($sepa)->toBeArray();
        expect($sepa)->toHaveKeys([
            'msgId',
            'acctSvcrRef',
            'endToEndId',
            'instrId',
            'txId',
            'mandateId',
            'pmtInfId',
            'btc',
            'remittanceUnstructured',
        ]);
    }
})->group('phase-2');

it('rejects a file that fails the sniffer before reading any data', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'camt-wrong-').'.xml';
    file_put_contents($tmp, '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.08"/>');

    try {
        expect(function () use ($tmp): void {
            iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);
        })->toThrow(SniffMismatchException::class);
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('populates sourceRef from EndToEndId when present and never from a weaker ref', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-camt053-sample-1.xml'), $this->resolver),
        preserve_keys: false,
    );

    $hasAtLeastOneEndToEndId = false;
    foreach ($dtos as $dto) {
        if ($dto->sourceRef !== null) {
            expect($dto->sourceRef)->not->toBe('NOTPROVIDED');
            expect($dto->sourceRef)->not->toBe('');
            $hasAtLeastOneEndToEndId = true;
        }
    }

    expect($hasAtLeastOneEndToEndId)->toBeTrue();
})->group('phase-2');

it('signs the amount per CreditDebitIndicator (DBIT negative, CRDT positive)', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-camt053-sample-1.xml'), $this->resolver),
        preserve_keys: false,
    );

    $debits = array_filter($dtos, static fn (SourceTransactionDto $d): bool => $d->amountMinor < 0);
    $credits = array_filter($dtos, static fn (SourceTransactionDto $d): bool => $d->amountMinor > 0);

    expect($debits)->not->toBeEmpty();
    expect($credits)->not->toBeEmpty();
})->group('phase-2');

it('normalises a date-only BookgDt to 00:00:00 so cross-format dedup with CSV survives', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-camt053-sample-1.xml'), $this->resolver),
        preserve_keys: false,
    );

    // Every BookgDt in the fixture is date-only, and the fingerprint hash only
    // matches the equivalent CSV row if the time is zeroed the same way.
    foreach ($dtos as $dto) {
        expect($dto->bookedAt->format('H:i:s'))->toBe('00:00:00');
    }
})->group('phase-2');

it('rejects a CAMT entry that is missing both BookgDt and ValDt to keep the fingerprint deterministic', function (): void {
    $xml = <<<'XML'
<?xml version='1.0' encoding='UTF-8'?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr>
            <MsgId>CAMT053-NO-DATES</MsgId>
            <CreDtTm>2026-05-12T21:12:27.273942228+02:00</CreDtTm>
        </GrpHdr>
        <Stmt>
            <Id>CAMT053-NO-DATES-0001</Id>
            <ElctrncSeqNb>1</ElctrncSeqNb>
            <CreDtTm>2026-05-12T21:12:27.229917934+02:00</CreDtTm>
            <Acct>
                <Id><IBAN>NL57ASNB0123456789</IBAN></Id>
                <Ccy>EUR</Ccy>
                <Svcr><FinInstnId><BIC>ASNBNL21</BIC></FinInstnId></Svcr>
            </Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">100.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">100.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-28</Dt></Dt>
            </Bal>
            <Ntry>
                <NtryRef>NO-DATES</NtryRef>
                <Amt Ccy="EUR">1.23</Amt>
                <CdtDbtInd>DBIT</CdtDbtInd>
                <Sts>BOOK</Sts>
                <BkTxCd>
                    <Domn><Cd>PMNT</Cd><Fmly><Cd>RDDT</Cd><SubFmlyCd>ESDD</SubFmlyCd></Fmly></Domn>
                </BkTxCd>
                <NtryDtls>
                    <TxDtls>
                        <Refs><EndToEndId>NODATE-E2E</EndToEndId></Refs>
                    </TxDtls>
                </NtryDtls>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;

    $tmp = tempnam(sys_get_temp_dir(), 'camt-no-dates-').'.xml';
    file_put_contents($tmp, $xml);

    try {
        expect(function () use ($tmp): void {
            iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);
        })->toThrow(InvalidAmountException::class, 'missing both BookgDt and ValDt');
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('returns a null description when the only remittance present is structured (no Ustrd block)', function (): void {
    $xml = <<<'XML'
<?xml version='1.0' encoding='UTF-8'?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr>
            <MsgId>CAMT053-STRD-ONLY</MsgId>
            <CreDtTm>2026-05-12T21:12:27.273942228+02:00</CreDtTm>
        </GrpHdr>
        <Stmt>
            <Id>CAMT053-STRD-ONLY-0001</Id>
            <ElctrncSeqNb>1</ElctrncSeqNb>
            <CreDtTm>2026-05-12T21:12:27.229917934+02:00</CreDtTm>
            <Acct>
                <Id><IBAN>NL57ASNB0123456789</IBAN></Id>
                <Ccy>EUR</Ccy>
                <Svcr><FinInstnId><BIC>ASNBNL21</BIC></FinInstnId></Svcr>
            </Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">100.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">100.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-28</Dt></Dt>
            </Bal>
            <Ntry>
                <NtryRef>STRD-1</NtryRef>
                <Amt Ccy="EUR">1.23</Amt>
                <CdtDbtInd>DBIT</CdtDbtInd>
                <Sts>BOOK</Sts>
                <BookgDt><Dt>2026-02-02</Dt></BookgDt>
                <ValDt><Dt>2026-02-02</Dt></ValDt>
                <BkTxCd>
                    <Domn><Cd>PMNT</Cd><Fmly><Cd>RDDT</Cd><SubFmlyCd>ESDD</SubFmlyCd></Fmly></Domn>
                </BkTxCd>
                <NtryDtls>
                    <TxDtls>
                        <Refs><EndToEndId>STRD-E2E</EndToEndId></Refs>
                        <RmtInf>
                            <Strd>
                                <CdtrRefInf>
                                    <Ref>STRUCTURED-ONLY-REF</Ref>
                                </CdtrRefInf>
                            </Strd>
                        </RmtInf>
                    </TxDtls>
                </NtryDtls>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;

    $tmp = tempnam(sys_get_temp_dir(), 'camt-strd-').'.xml';
    file_put_contents($tmp, $xml);

    try {
        $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);
        expect($dtos)->toHaveCount(1);
        expect($dtos[0]->description)->toBeNull();
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('emits monotonically increasing sourceRowIndex starting at zero', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-camt053-sample-1.xml'), $this->resolver),
        preserve_keys: false,
    );

    $expectedIndex = 0;
    foreach ($dtos as $dto) {
        expect($dto->sourceRowIndex)->toBe($expectedIndex);
        $expectedIndex++;
    }
})->group('phase-2');
