<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// A batch booking whose children spell their amount as TxDtls/Amt, which is the
// ordinary ISO 20022 element — AmtDtls/TxAmt carries the instructed amount and
// a bank that converts nothing has no reason to write one.
function buildBatchCamt053DocWithPlainChildAmounts(): string
{
    $txDtls = '';
    foreach ([50, 60, 40] as $index => $major) {
        $txDtls .= sprintf(
            <<<'XML'
                    <TxDtls>
                        <Refs>
                            <EndToEndId>PLAIN-EREF-%d</EndToEndId>
                        </Refs>
                        <Amt Ccy="EUR">%d.00</Amt>
                        <RltdPties>
                            <Cdtr>
                                <Nm>Batch Counterparty %d</Nm>
                            </Cdtr>
                        </RltdPties>
                    </TxDtls>

XML,
            $index + 1,
            $major,
            $index + 1,
        );
    }

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr>
            <MsgId>PLAIN-BATCH</MsgId>
            <CreDtTm>2026-02-01T09:00:00+01:00</CreDtTm>
        </GrpHdr>
        <Stmt>
            <Id>PLAIN-BATCH-STMT</Id>
            <CreDtTm>2026-02-01T09:00:00+01:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">1000.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">850.00</Amt><CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-02</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">150.00</Amt>
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

it('reads a batch child amount written as TxDtls/Amt rather than the entry total', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'camt-plain-batch-').'.xml';
    file_put_contents($tmp, buildBatchCamt053DocWithPlainChildAmounts());

    try {
        $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);

        $amounts = array_map(static fn (SourceTransactionDto $d): int => $d->amountMinor, $dtos);

        expect($amounts)->toBe([-5000, -6000, -4000])
            ->and(array_sum($amounts))->toBe(-15000);
    } finally {
        @unlink($tmp);
    }
});
