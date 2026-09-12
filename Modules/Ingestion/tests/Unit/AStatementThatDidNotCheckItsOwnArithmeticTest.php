<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ledger\Public\Dto\StatementSummaryData;

// CAMT.053 and MT940 both state what the account held before the first entry
// and after the last, which makes the file self-checking: opening plus every
// entry has to reach closing. Nothing computed it, so a dropped entry, a
// doubled one or a direction read backwards left a ledger that disagreed with
// the bank by an amount no screen ever worked out.
function selfCheckCamtDoc(string $openingBal, string $closingBal, string $entries): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr><MsgId>SELF-CHECK</MsgId><CreDtTm>2026-03-01T09:00:00+01:00</CreDtTm></GrpHdr>
        <Stmt>
            <Id>SELF-CHECK-STMT</Id>
            <CreDtTm>2026-03-01T09:00:00+01:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
{$openingBal}
{$closingBal}
{$entries}
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;
}

function selfCheckBalance(string $code, string $currency, string $amount, string $date): string
{
    return <<<XML
            <Bal>
                <Tp><CdOrPrtry><Cd>{$code}</Cd></CdOrPrtry></Tp>
                <Amt Ccy="{$currency}">{$amount}</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>{$date}</Dt></Dt>
            </Bal>
XML;
}

function selfCheckEntry(string $currency, string $amount): string
{
    return <<<XML
            <Ntry>
                <Amt Ccy="{$currency}">{$amount}</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-03-01</Dt></BookgDt><ValDt><Dt>2026-03-01</Dt></ValDt>
            </Ntry>
XML;
}

function selfCheckCamtMeta(Camt053Adapter $adapter, AccountResolver $resolver, string $xml): ?StatementSummaryData
{
    $tmp = tempnam(sys_get_temp_dir(), 'camt-self-check-').'.xml';
    file_put_contents($tmp, $xml);

    try {
        iterator_to_array($adapter->parse($tmp, $resolver), preserve_keys: false);

        return $adapter->statementMetadata();
    } finally {
        @unlink($tmp);
    }
}

function selfCheckMt940Meta(Mt940Adapter $adapter, AccountResolver $resolver, string $body): ?StatementSummaryData
{
    $tmp = tempnam(sys_get_temp_dir(), 'mt940-self-check-').'.940';
    file_put_contents($tmp, $body);

    try {
        iterator_to_array($adapter->parse($tmp, $resolver), preserve_keys: false);

        return $adapter->statementMetadata();
    } finally {
        @unlink($tmp);
    }
}

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $this->resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->camt = $this->app->make(Camt053Adapter::class);
    $this->mt940 = $this->app->make(Mt940Adapter::class);
});

it('states the difference a CAMT.053 statement leaves against its own balances', function (): void {
    $meta = selfCheckCamtMeta($this->camt, $this->resolver, selfCheckCamtDoc(
        selfCheckBalance('OPBD', 'EUR', '1000.00', '2026-02-28'),
        selfCheckBalance('CLBD', 'EUR', '900.00', '2026-03-01'),
        selfCheckEntry('EUR', '90.00'),
    ));

    expect($meta->extras['statementDifferenceMinor'])->toBe(-1000);
});

it('corrects nothing it noticed, leaving both balances and the entry as the file wrote them', function (): void {
    $meta = selfCheckCamtMeta($this->camt, $this->resolver, selfCheckCamtDoc(
        selfCheckBalance('OPBD', 'EUR', '1000.00', '2026-02-28'),
        selfCheckBalance('CLBD', 'EUR', '900.00', '2026-03-01'),
        selfCheckEntry('EUR', '90.00'),
    ));

    expect($meta->openingBalanceMinor)->toBe(100000)
        ->and($meta->closingBalanceMinor)->toBe(90000)
        ->and($meta->entryCount)->toBe(1);
});

it('states nothing about a CAMT.053 statement that adds up', function (): void {
    $meta = selfCheckCamtMeta($this->camt, $this->resolver, selfCheckCamtDoc(
        selfCheckBalance('OPBD', 'EUR', '1000.00', '2026-02-28'),
        selfCheckBalance('CLBD', 'EUR', '900.00', '2026-03-01'),
        selfCheckEntry('EUR', '100.00'),
    ));

    expect($meta->extras)->not->toHaveKey('statementDifferenceMinor');
});

it('reads a previous-closing balance as the opening one and checks against it', function (): void {
    $meta = selfCheckCamtMeta($this->camt, $this->resolver, selfCheckCamtDoc(
        selfCheckBalance('PRCD', 'EUR', '1000.00', '2026-02-28'),
        selfCheckBalance('CLBD', 'EUR', '900.00', '2026-03-01'),
        selfCheckEntry('EUR', '90.00'),
    ));

    expect($meta->openingBalanceMinor)->toBe(100000)
        ->and($meta->extras['statementDifferenceMinor'])->toBe(-1000);
});

it('checks nothing where the statement states no opening balance', function (): void {
    $meta = selfCheckCamtMeta($this->camt, $this->resolver, selfCheckCamtDoc(
        '',
        selfCheckBalance('CLBD', 'EUR', '900.00', '2026-03-01'),
        selfCheckEntry('EUR', '90.00'),
    ));

    expect($meta->openingBalanceMinor)->toBeNull()
        ->and($meta->extras)->not->toHaveKey('statementDifferenceMinor');
});

it('checks nothing where the two balances are in different currencies', function (): void {
    $meta = selfCheckCamtMeta($this->camt, $this->resolver, selfCheckCamtDoc(
        selfCheckBalance('OPBD', 'EUR', '1000.00', '2026-02-28'),
        selfCheckBalance('CLBD', 'USD', '900.00', '2026-03-01'),
        selfCheckEntry('EUR', '90.00'),
    ));

    expect($meta->extras)->not->toHaveKey('statementDifferenceMinor');
});

it('checks nothing where an entry is denominated in something the balances are not', function (): void {
    $meta = selfCheckCamtMeta($this->camt, $this->resolver, selfCheckCamtDoc(
        selfCheckBalance('OPBD', 'EUR', '1000.00', '2026-02-28'),
        selfCheckBalance('CLBD', 'EUR', '900.00', '2026-03-01'),
        selfCheckEntry('EUR', '40.00').selfCheckEntry('USD', '50.00'),
    ));

    expect($meta->extras)->not->toHaveKey('statementDifferenceMinor');
});

it('states the difference an MT940 statement leaves against its own balances', function (): void {
    $meta = selfCheckMt940Meta($this->mt940, $this->resolver,
        ":20:SELF-CHECK\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401D10,00NTRFX-1\n:86:100?32Payer\n"
        .":62F:C260430EUR980,00\n-\n");

    expect($meta->extras['statementDifferenceMinor'])->toBe(-1000);
});

it('states nothing about an MT940 statement that adds up', function (): void {
    $meta = selfCheckMt940Meta($this->mt940, $this->resolver,
        ":20:SELF-CHECK\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401D10,00NTRFX-1\n:86:100?32Payer\n"
        .":62F:C260430EUR990,00\n-\n");

    expect($meta->extras)->not->toHaveKey('statementDifferenceMinor');
});

it('checks nothing where the MT940 statement never closed', function (): void {
    $meta = selfCheckMt940Meta($this->mt940, $this->resolver,
        ":20:SELF-CHECK\n:25:NL57ASNB0123456789\n:60F:C260401EUR1000,00\n"
        .":61:2604010401D10,00NTRFX-1\n:86:100?32Payer\n-\n");

    expect($meta->closingBalanceMinor)->toBeNull()
        ->and($meta->extras)->not->toHaveKey('statementDifferenceMinor');
});

it('finds both shipped bank fixtures adding up to the balance they state', function (): void {
    $camt = $this->camt;
    iterator_to_array($camt->parse(base_path('tests/fixtures/asn-camt053-sample-1.xml'), $this->resolver), preserve_keys: false);
    iterator_to_array($this->mt940->parse(base_path('tests/fixtures/asn-mt940-sample-1.sta'), $this->resolver), preserve_keys: false);

    expect($camt->statementMetadata()->extras)->not->toHaveKey('statementDifferenceMinor')
        ->and($this->mt940->statementMetadata()->extras)->not->toHaveKey('statementDifferenceMinor');
});
