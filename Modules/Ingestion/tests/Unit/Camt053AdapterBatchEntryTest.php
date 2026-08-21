<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

function buildBatchCamt053Doc(int $count): string
{
    $txDtls = '';
    for ($i = 1; $i <= $count; $i++) {
        $txDtls .= sprintf(
            <<<'XML'
                    <TxDtls>
                        <Refs>
                            <EndToEndId>BATCH-EREF-%d</EndToEndId>
                        </Refs>
                        <AmtDtls>
                            <TxAmt>
                                <Amt Ccy="EUR">%d.00</Amt>
                            </TxAmt>
                        </AmtDtls>
                        <RltdPties>
                            <Cdtr>
                                <Nm>Batch Counterparty %d</Nm>
                            </Cdtr>
                        </RltdPties>
                    </TxDtls>

XML,
            $i,
            $i * 10,
            $i,
        );
    }

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr>
            <MsgId>BATCH-TEST</MsgId>
            <CreDtTm>2026-02-01T09:00:00+01:00</CreDtTm>
        </GrpHdr>
        <Stmt>
            <Id>BATCH-STMT</Id>
            <CreDtTm>2026-02-01T09:00:00+01:00</CreDtTm>
            <Acct>
                <Id>
                    <IBAN>NL57ASNB0123456789</IBAN>
                </Id>
                <Ccy>EUR</Ccy>
            </Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">1000.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">940.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-02</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">60.00</Amt>
                <CdtDbtInd>DBIT</CdtDbtInd>
                <Sts>BOOK</Sts>
                <BookgDt><Dt>2026-02-02</Dt></BookgDt>
                <ValDt><Dt>2026-02-02</Dt></ValDt>
                <NtryDtls>
{$txDtls}                </NtryDtls>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;
}

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

it('yields N DTOs from one Ntry with N TxDtls children', function (): void {
    $xml = buildBatchCamt053Doc(count: 3);
    $tmp = tempnam(sys_get_temp_dir(), 'camt-batch-').'.xml';
    file_put_contents($tmp, $xml);

    try {
        $dtos = iterator_to_array(
            $this->adapter->parse($tmp, $this->resolver),
            preserve_keys: false,
        );

        expect($dtos)->toHaveCount(3);
        $endToEndIds = array_map(static fn (SourceTransactionDto $d): ?string => $d->sourceRef, $dtos);
        expect($endToEndIds)->toBe(['BATCH-EREF-1', 'BATCH-EREF-2', 'BATCH-EREF-3']);

        // Each child's own AmtDtls amount, not a share of the entry-level 60.00.
        $amounts = array_map(static fn (SourceTransactionDto $d): int => $d->amountMinor, $dtos);
        expect($amounts)->toBe([-1000, -2000, -3000]);
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('falls back to entry-level amount when an Ntry has zero TxDtls children', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr><MsgId>NO-TXDTLS</MsgId><CreDtTm>2026-02-01T09:00:00+01:00</CreDtTm></GrpHdr>
        <Stmt>
            <Id>STMT</Id>
            <CreDtTm>2026-02-01T09:00:00+01:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">95.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-02</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">5.00</Amt>
                <CdtDbtInd>DBIT</CdtDbtInd>
                <Sts>BOOK</Sts>
                <BookgDt><Dt>2026-02-02</Dt></BookgDt>
                <ValDt><Dt>2026-02-02</Dt></ValDt>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;
    $tmp = tempnam(sys_get_temp_dir(), 'camt-no-txdtls-').'.xml';
    file_put_contents($tmp, $xml);

    try {
        $dtos = iterator_to_array(
            $this->adapter->parse($tmp, $this->resolver),
            preserve_keys: false,
        );

        expect($dtos)->toHaveCount(1);
        expect($dtos[0]->amountMinor)->toBe(-500);
        expect($dtos[0]->sourceRef)->toBeNull();
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');

it('does not resolve external entities in CAMT XML (XXE-safe)', function (): void {
    $xxePayload = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE foo [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr><MsgId>XXE-TEST</MsgId><CreDtTm>2026-02-01T09:00:00+01:00</CreDtTm></GrpHdr>
        <Stmt>
            <Id>&xxe;</Id>
            <CreDtTm>2026-02-01T09:00:00+01:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">95.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-02</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">5.00</Amt>
                <CdtDbtInd>DBIT</CdtDbtInd>
                <Sts>BOOK</Sts>
                <BookgDt><Dt>2026-02-02</Dt></BookgDt>
                <ValDt><Dt>2026-02-02</Dt></ValDt>
                <NtryDtls>
                    <TxDtls>
                        <Refs>
                            <EndToEndId>&xxe;</EndToEndId>
                        </Refs>
                        <RltdPties>
                            <Cdtr><Nm>&xxe;</Nm></Cdtr>
                        </RltdPties>
                    </TxDtls>
                </NtryDtls>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;
    $tmp = tempnam(sys_get_temp_dir(), 'camt-xxe-').'.xml';
    file_put_contents($tmp, $xxePayload);

    try {
        // Rejecting the DTD outright and leaving the entity unexpanded are both
        // acceptable; what neither may do is put "root:" in a DTO field.
        $dtos = [];
        try {
            $dtos = iterator_to_array(
                $this->adapter->parse($tmp, $this->resolver),
                preserve_keys: false,
            );
        } catch (Throwable) {
            $dtos = [];
        }

        foreach ($dtos as $dto) {
            $serialized = json_encode([
                'sourceRef' => $dto->sourceRef,
                'description' => $dto->description,
                'counterpartyName' => $dto->counterpartyName,
                'rawPayload' => $dto->rawPayload,
            ]);
            expect($serialized)->not->toContain('root:');
        }
    } finally {
        @unlink($tmp);
    }
})->group('phase-2');
