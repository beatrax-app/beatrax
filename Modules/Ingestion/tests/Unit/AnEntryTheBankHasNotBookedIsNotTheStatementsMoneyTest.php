<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// A CAMT.053 entry states its own status, and the closing balance counts only
// what the bank BOOKED. A pending authorisation and an informational line are
// not in that figure; booked anyway they spend money the account still holds,
// and book a second time on the statement that really settles them. The file
// below says so in its own arithmetic: 1000.00 - 100.00 = 900.00, and the
// pending 25.00 and informational 5.00 are in neither side of it.
function unbookedEntriesCamtDoc(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr><MsgId>UNBOOKED</MsgId><CreDtTm>2026-03-01T09:00:00+01:00</CreDtTm></GrpHdr>
        <Stmt>
            <Id>UNBOOKED-STMT</Id>
            <CreDtTm>2026-03-01T09:00:00+01:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">1000.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-02-28</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">900.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-03-01</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">100.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-03-01</Dt></BookgDt><ValDt><Dt>2026-03-01</Dt></ValDt>
                <NtryDtls><TxDtls>
                    <Refs><EndToEndId>BOOKED-1</EndToEndId></Refs>
                    <RltdPties><Cdtr><Nm>Booked Shop</Nm></Cdtr></RltdPties>
                </TxDtls></NtryDtls>
            </Ntry>
            <Ntry>
                <Amt Ccy="EUR">25.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>PDNG</Sts>
                <BookgDt><Dt>2026-03-01</Dt></BookgDt><ValDt><Dt>2026-03-01</Dt></ValDt>
                <NtryDtls><TxDtls>
                    <Refs><EndToEndId>PENDING-1</EndToEndId></Refs>
                    <RltdPties><Cdtr><Nm>Pending Shop</Nm></Cdtr></RltdPties>
                </TxDtls></NtryDtls>
            </Ntry>
            <Ntry>
                <Amt Ccy="EUR">5.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts><Cd>INFO</Cd></Sts>
                <BookgDt><Dt>2026-03-01</Dt></BookgDt><ValDt><Dt>2026-03-01</Dt></ValDt>
                <NtryDtls><TxDtls>
                    <Refs><EndToEndId>INFO-1</EndToEndId></Refs>
                    <RltdPties><Cdtr><Nm>Advice Only</Nm></Cdtr></RltdPties>
                </TxDtls></NtryDtls>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;
}

// An entry stating no status at all: the sub-versions that make <Sts> optional
// mean absent, and refusing those would drop a whole statement's rows.
function unbookedEntriesStatuslessCamtDoc(): string
{
    return str_replace(
        ['<Sts>BOOK</Sts>', '<Sts>PDNG</Sts>', '<Sts><Cd>INFO</Cd></Sts>'],
        ['', '<Sts>PDNG</Sts>', '<Sts><Cd>INFO</Cd></Sts>'],
        unbookedEntriesCamtDoc(),
    );
}

/**
 * @return list<SourceTransactionDto>
 */
function unbookedEntriesParse(Camt053Adapter $adapter, AccountResolver $resolver, string $xml): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'camt-unbooked-').'.xml';
    file_put_contents($tmp, $xml);

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

it('books the entry the bank booked and neither the pending one nor the advice', function (): void {
    $dtos = unbookedEntriesParse($this->adapter, $this->resolver, unbookedEntriesCamtDoc());

    expect($dtos)->toHaveCount(1)
        ->and($dtos[0]->sourceRef)->toBe('BOOKED-1')
        ->and($dtos[0]->amountMinor)->toBe(-10000);
});

it('leaves the entries it did not book out of the count the summary publishes', function (): void {
    unbookedEntriesParse($this->adapter, $this->resolver, unbookedEntriesCamtDoc());
    $meta = $this->adapter->statementMetadata();

    expect($meta)->not->toBeNull()
        ->and($meta->entryCount)->toBe(1);
});

it('reads back a statement that adds up once the unbooked entries are out of it', function (): void {
    unbookedEntriesParse($this->adapter, $this->resolver, unbookedEntriesCamtDoc());
    $meta = $this->adapter->statementMetadata();

    expect($meta->openingBalanceMinor)->toBe(100000)
        ->and($meta->closingBalanceMinor)->toBe(90000)
        ->and($meta->extras)->not->toHaveKey('statementDifferenceMinor');
});

it('treats an entry that states no status at all as booked', function (): void {
    $dtos = unbookedEntriesParse($this->adapter, $this->resolver, unbookedEntriesStatuslessCamtDoc());

    expect($dtos)->toHaveCount(1)
        ->and($dtos[0]->sourceRef)->toBe('BOOKED-1');
});
