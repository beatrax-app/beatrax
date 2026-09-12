<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// genkgo/camt hands the ENTRY's <CdtDbtInd> to every child it builds
// (Decoder/Entry::addTransactionDetails), so TxDtls/CdtDbtInd reaches no DTO at
// all and asking the child for its direction answers with the batch total's. A
// CRDT child of EUR 25.00 inside a DBIT batch was therefore booked -2500, and
// its counterparty was read off the creditor side a debit takes.
//
// The document below puts the batch in the SECOND <Stmt>, behind an entry with
// no children at all, so the statement/entry/detail ordinals the second pass
// keys on are all exercised rather than a flat position that happens to agree.
function batchDirectionsCamtDoc(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr><MsgId>MIXED-BATCH</MsgId><CreDtTm>2026-03-01T09:00:00+01:00</CreDtTm></GrpHdr>
        <Stmt>
            <Id>MIXED-BATCH-ONE</Id>
            <CreDtTm>2026-03-01T09:00:00+01:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">1000.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-03-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">993.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-03-02</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">7.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-03-02</Dt></BookgDt><ValDt><Dt>2026-03-02</Dt></ValDt>
            </Ntry>
        </Stmt>
        <Stmt>
            <Id>MIXED-BATCH-TWO</Id>
            <CreDtTm>2026-03-01T09:00:00+01:00</CreDtTm>
            <Acct><Id><IBAN>NL91ABNA0417164300</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">2000.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-03-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">1900.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-03-02</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">100.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-03-02</Dt></BookgDt><ValDt><Dt>2026-03-02</Dt></ValDt>
                <NtryDtls>
                    <TxDtls>
                        <Refs><EndToEndId>MIXED-1</EndToEndId></Refs>
                        <Amt Ccy="EUR">50.00</Amt>
                        <RltdPties><Cdtr><Nm>Paid Supplier</Nm></Cdtr></RltdPties>
                    </TxDtls>
                    <TxDtls>
                        <Refs><EndToEndId>MIXED-2</EndToEndId></Refs>
                        <Amt Ccy="EUR">25.00</Amt>
                        <CdtDbtInd>CRDT</CdtDbtInd>
                        <RltdPties>
                            <Cdtr><Nm>Creditor Side</Nm></Cdtr>
                            <Dbtr><Nm>Refunding Supplier</Nm></Dbtr>
                        </RltdPties>
                    </TxDtls>
                    <TxDtls>
                        <Refs><EndToEndId>MIXED-3</EndToEndId></Refs>
                        <Amt Ccy="EUR">75.00</Amt>
                        <CdtDbtInd>DBIT</CdtDbtInd>
                        <RltdPties><Cdtr><Nm>Other Supplier</Nm></Cdtr></RltdPties>
                    </TxDtls>
                </NtryDtls>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;
}

/**
 * @return list<SourceTransactionDto>
 */
function batchDirectionsParse(Camt053Adapter $adapter, AccountResolver $resolver): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'camt-mixed-batch-').'.xml';
    file_put_contents($tmp, batchDirectionsCamtDoc());

    try {
        return iterator_to_array($adapter->parse($tmp, $resolver), preserve_keys: false);
    } finally {
        @unlink($tmp);
    }
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

it('signs each batch child by the direction the child states, not the batch total', function (): void {
    $dtos = batchDirectionsParse($this->adapter, $this->resolver);

    $amounts = array_map(static fn (SourceTransactionDto $d): int => $d->amountMinor, $dtos);

    // The lone entry, then the batch: +2500 is the child that says CRDT inside a
    // DBIT batch, which took -2500 when the batch total answered for it.
    expect($amounts)->toBe([-700, -5000, 2500, -7500]);
});

it('reads the counterparty of a credit child off the debtor side', function (): void {
    $dtos = batchDirectionsParse($this->adapter, $this->resolver);

    $credited = $dtos[2];

    expect($credited->sourceRef)->toBe('MIXED-2')
        ->and($credited->counterpartyName)->toBe('Refunding Supplier')
        ->and($credited->rawPayload['sepa']['creditDebitIndicator'])->toBe('CRDT');
});

it('leaves a child that states no direction on the entry\'s', function (): void {
    $dtos = batchDirectionsParse($this->adapter, $this->resolver);

    $unstated = $dtos[1];

    expect($unstated->sourceRef)->toBe('MIXED-1')
        ->and($unstated->amountMinor)->toBe(-5000)
        ->and($unstated->counterpartyName)->toBe('Paid Supplier');
});
