<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;

// A bank export holding several statements was read one way by MT940 — first
// statement pinned, `multiStatement` flagged — and the opposite way by CAMT,
// which silently kept the last. The reader importing such a file lost the
// earlier statements' summary with nothing on screen saying so. Both files
// below describe the same two statements, so the two parsers are asked the
// same question here and have to give the same answer.
function twoParsersCamtDoc(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr>
            <MsgId>TWO-PARSERS</MsgId>
            <CreDtTm>2026-06-01T09:00:00+02:00</CreDtTm>
        </GrpHdr>
        <Stmt>
            <Id>PARSERS-APRIL</Id>
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
            <Id>PARSERS-MAY</Id>
            <CreDtTm>2026-06-01T09:00:00+02:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">1050.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-05-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">1250.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-05-31</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">200.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-05-01</Dt></BookgDt><ValDt><Dt>2026-05-01</Dt></ValDt>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;
}

function twoParsersMt940Body(): string
{
    return ":20:PARSERS-APRIL\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401C100,00NTRFA-1\n:86:100?32X\n"
        .":61:2604020402D50,00NTRFA-2\n:86:100?32Y\n"
        .":62F:C260430EUR1050,00\n-\n"
        .":20:PARSERS-MAY\n:25:NL57ASNB0123456789\n:60F:C260501EUR1050,00\n"
        .":61:2605010501C200,00NTRFB-1\n:86:100?32Z\n"
        .":62F:C260531EUR1250,00\n-\n";
}

function twoParsersWriteTemp(string $body, string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'two-parsers-').$extension;
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

it('pins the first statement and flags the file, whichever parser read it', function (string $adapterClass, string $body, string $extension): void {
    $path = twoParsersWriteTemp($body, $extension);

    try {
        $adapter = $this->app->make($adapterClass);
        $dtos = iterator_to_array($adapter->parse($path, $this->resolver), preserve_keys: false);
        $meta = $adapter->statementMetadata();

        expect($dtos)->toHaveCount(3)
            ->and($meta)->not->toBeNull();

        expect($meta->extras['statementId'])->toBe('PARSERS-APRIL')
            ->and($meta->extras)->toHaveKey('multiStatement')
            ->and($meta->extras['multiStatement'])->toBeTrue()
            ->and($meta->entryCount)->toBe(2)
            ->and($meta->closingBalanceDate?->toDateString())->toBe('2026-04-30')
            ->and($meta->closingBalanceMinor)->toBe(105000)
            ->and($meta->openingBalanceDate?->toDateString())->toBe('2026-04-01');
    } finally {
        @unlink($path);
    }
})->with([
    'camt' => [Camt053Adapter::class, twoParsersCamtDoc(), '.xml'],
    'mt940' => [Mt940Adapter::class, twoParsersMt940Body(), '.940'],
]);

it('leaves a single-statement file unflagged, whichever parser read it', function (string $adapterClass, string $body, string $extension): void {
    $path = twoParsersWriteTemp($body, $extension);

    try {
        $adapter = $this->app->make($adapterClass);
        iterator_to_array($adapter->parse($path, $this->resolver), preserve_keys: false);
        $meta = $adapter->statementMetadata();

        expect($meta)->not->toBeNull()
            ->and($meta->extras)->not->toHaveKey('multiStatement');
    } finally {
        @unlink($path);
    }
})->with([
    'camt' => [
        Camt053Adapter::class,
        str_replace('PARSERS-MAY', 'PARSERS-APRIL', preg_replace('#<Stmt>\s*<Id>PARSERS-MAY.*?</Stmt>#s', '', twoParsersCamtDoc()) ?? ''),
        '.xml',
    ],
    'mt940' => [
        Mt940Adapter::class,
        ":20:PARSERS-APRIL\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
            .":61:2604010401C100,00NTRFA-1\n:86:100?32X\n"
            .":62F:C260430EUR1050,00\n-\n",
        '.940',
    ],
]);
