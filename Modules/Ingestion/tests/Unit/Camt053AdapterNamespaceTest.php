<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;

// genkgo/camt takes every 001.NN sub-version through one Reader, so the adapter
// needs no branching and one document body covers them all.
function minimalCamt053Doc(string $nsVersion): string
{
    return sprintf(
        <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.%s">
    <BkToCstmrStmt>
        <GrpHdr>
            <MsgId>NS-TEST-%s</MsgId>
            <CreDtTm>2026-02-01T09:00:00+01:00</CreDtTm>
        </GrpHdr>
        <Stmt>
            <Id>NS-TEST-STMT-%s</Id>
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
                <Amt Ccy="EUR">990.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-02</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">10.00</Amt>
                <CdtDbtInd>DBIT</CdtDbtInd>
                <Sts>BOOK</Sts>
                <BookgDt><Dt>2026-02-02</Dt></BookgDt>
                <ValDt><Dt>2026-02-02</Dt></ValDt>
                <NtryDtls>
                    <TxDtls>
                        <Refs>
                            <EndToEndId>NS-EREF-%s</EndToEndId>
                        </Refs>
                        <RltdPties>
                            <Cdtr>
                                <Nm>Albert Heijn</Nm>
                            </Cdtr>
                        </RltdPties>
                    </TxDtls>
                </NtryDtls>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML,
        $nsVersion,
        $nsVersion,
        $nsVersion,
        $nsVersion,
    );
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

it('parses CAMT.053 sub-version 001.NN via the same code path', function (string $nsVersion): void {
    $xml = minimalCamt053Doc($nsVersion);
    $tmp = tempnam(sys_get_temp_dir(), 'camt-ns-').'.xml';
    file_put_contents($tmp, $xml);

    try {
        $dtos = iterator_to_array(
            $this->adapter->parse($tmp, $this->resolver),
            preserve_keys: false,
        );

        expect($dtos)->toHaveCount(1);
        expect($dtos[0]->amountMinor)->toBe(-1000);
        expect($dtos[0]->currency)->toBe('EUR');
        expect($dtos[0]->ownIban)->toBe('NL57ASNB0123456789');
        expect($dtos[0]->sourceRef)->toBe(sprintf('NS-EREF-%s', $nsVersion));
    } finally {
        @unlink($tmp);
    }
})->with([
    '001.02' => '02',
    '001.03' => '03',
    '001.08' => '08',
])->group('phase-2');
