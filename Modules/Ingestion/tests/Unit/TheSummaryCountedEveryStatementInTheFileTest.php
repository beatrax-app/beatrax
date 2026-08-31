<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;

// One CAMT.053 message may carry several <Stmt> records. The adapter publishes
// one statement's balances and period, so its entry count has to be that same
// statement's; running the counter across the whole message described a single
// statement as holding all three entries the file contained.
function twoStatementCamt053Doc(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr>
            <MsgId>TWO-STMT</MsgId>
            <CreDtTm>2026-06-01T09:00:00+02:00</CreDtTm>
        </GrpHdr>
        <Stmt>
            <Id>TWO-STMT-APRIL</Id>
            <CreDtTm>2026-06-01T09:00:00+02:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">85.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-30</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">10.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-04-02</Dt></BookgDt><ValDt><Dt>2026-04-02</Dt></ValDt>
            </Ntry>
            <Ntry>
                <Amt Ccy="EUR">5.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-04-03</Dt></BookgDt><ValDt><Dt>2026-04-03</Dt></ValDt>
            </Ntry>
        </Stmt>
        <Stmt>
            <Id>TWO-STMT-MAY</Id>
            <CreDtTm>2026-06-01T09:00:00+02:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">85.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-05-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">80.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-05-31</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">5.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-05-02</Dt></BookgDt><ValDt><Dt>2026-05-02</Dt></ValDt>
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

it('counts only the entries of the statement whose balances it publishes', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'camt-two-stmt-').'.xml';
    file_put_contents($tmp, twoStatementCamt053Doc());

    try {
        $dtos = iterator_to_array($this->adapter->parse($tmp, $this->resolver), preserve_keys: false);
        $meta = $this->adapter->statementMetadata();

        expect($dtos)->toHaveCount(3);
        expect($meta)->not->toBeNull();
        expect($meta->extras['statementId'])->toBe('TWO-STMT-APRIL');
        expect($meta->closingBalanceDate?->toDateString())->toBe('2026-04-30');
        expect($meta->entryCount)->toBe(2);
    } finally {
        @unlink($tmp);
    }
});
