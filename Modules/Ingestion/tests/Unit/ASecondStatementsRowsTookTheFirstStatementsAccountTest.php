<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// A bulk delivery holds one statement per account. CAMT resolves the own-IBAN
// inside its per-statement loop; MT940 froze it at the first :25: and stamped
// every later statement's entries with it, so the second account's rows were
// written into the first account -- and read at the first statement's currency
// scale, which for a yen statement under a euro one is a hundred times the
// figure. Both files below describe the same two statements.
function twoAccountsCamtDoc(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr><MsgId>TWO-ACCOUNTS</MsgId><CreDtTm>2026-06-01T09:00:00+02:00</CreDtTm></GrpHdr>
        <Stmt>
            <Id>ACCOUNTS-ASN</Id>
            <CreDtTm>2026-06-01T09:00:00+02:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">1000.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">1050.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-30</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-04-01</Dt></BookgDt><ValDt><Dt>2026-04-01</Dt></ValDt>
            </Ntry>
            <Ntry>
                <Amt Ccy="EUR">50.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-04-02</Dt></BookgDt><ValDt><Dt>2026-04-02</Dt></ValDt>
            </Ntry>
        </Stmt>
        <Stmt>
            <Id>ACCOUNTS-YEN</Id>
            <CreDtTm>2026-06-01T09:00:00+02:00</CreDtTm>
            <Acct><Id><IBAN>NL91ABNA0417164300</IBAN></Id><Ccy>JPY</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="JPY">500000</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="JPY">499000</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-30</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="JPY">1000</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-04-03</Dt></BookgDt><ValDt><Dt>2026-04-03</Dt></ValDt>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;
}

function twoAccountsMt940Body(): string
{
    return ":20:ACCOUNTS-ASN\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFA-1\n:86:100?32X\n"
        .":61:2604020402D50,00NTRFA-2\n:86:100?32Y\n"
        .":62F:C260430EUR1050,00\n-\n"
        .":20:ACCOUNTS-YEN\n:25:NL91ABNA0417164300\n:60F:C260401JPY500000,\n"
        .":61:2604030403D1000,NTRFB-1\n:86:100?32Z\n"
        .":62F:C260430JPY499000,\n-\n";
}

function twoAccountsWriteTemp(string $body, string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'two-accounts-').$extension;
    file_put_contents($path, $body);

    return $path;
}

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };
});

it('files every statement\'s rows under its own account and currency, whichever parser read it', function (string $adapterClass, string $body, string $extension): void {
    $path = twoAccountsWriteTemp($body, $extension);

    try {
        $dtos = iterator_to_array(
            $this->app->make($adapterClass)->parse($path, $this->resolver),
            preserve_keys: false,
        );

        expect($dtos)->toHaveCount(3);

        $shape = array_map(
            static fn (SourceTransactionDto $d): array => [$d->ownIban, $d->currency, $d->amountMinor],
            $dtos,
        );

        expect($shape)->toBe([
            ['NL57ASNB0123456789', 'EUR', 10000],
            ['NL57ASNB0123456789', 'EUR', -5000],
            ['NL91ABNA0417164300', 'JPY', -1000],
        ]);
    } finally {
        @unlink($path);
    }
})->with([
    'camt' => [Camt053Adapter::class, twoAccountsCamtDoc(), '.xml'],
    'mt940' => [Mt940Adapter::class, twoAccountsMt940Body(), '.940'],
]);

it('still publishes only the first statement, flagged, when the file holds two accounts', function (): void {
    $path = twoAccountsWriteTemp(twoAccountsMt940Body(), '.940');

    try {
        $adapter = $this->app->make(Mt940Adapter::class);
        iterator_to_array($adapter->parse($path, $this->resolver), preserve_keys: false);
        $meta = $adapter->statementMetadata();

        expect($meta)->not->toBeNull()
            ->and($meta->ibanOwner)->toBe('NL57ASNB0123456789')
            ->and($meta->extras['statementId'])->toBe('ACCOUNTS-ASN')
            ->and($meta->extras['multiStatement'])->toBeTrue()
            ->and($meta->entryCount)->toBe(2)
            ->and($meta->closingBalanceMinor)->toBe(105000);
    } finally {
        @unlink($path);
    }
});
