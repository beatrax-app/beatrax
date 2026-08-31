<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// ISO 20022 models CdtrAcct/Id as a CHOICE of IBAN or Othr, and a card
// settlement, a domestic non-IBAN account and a proprietary wallet all arrive
// on the Othr branch. Reading only the IBAN branch answered "no counterparty
// account" for every one of them.
function camt053DocWithCounterpartyAccount(string $accountIdXml): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr>
            <MsgId>OTHR-ACCT-TEST</MsgId>
            <CreDtTm>2026-05-19T08:00:00+02:00</CreDtTm>
        </GrpHdr>
        <Stmt>
            <Id>OTHR-ACCT-STMT</Id>
            <CreDtTm>2026-05-19T08:00:00+02:00</CreDtTm>
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
                <Dt><Dt>2026-05-19</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">900.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-05-19</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">100.00</Amt>
                <CdtDbtInd>DBIT</CdtDbtInd>
                <Sts>BOOK</Sts>
                <BookgDt><Dt>2026-05-19</Dt></BookgDt>
                <ValDt><Dt>2026-05-19</Dt></ValDt>
                <NtryDtls>
                    <TxDtls>
                        <RltdPties>
                            <Cdtr>
                                <Nm>ICS Cards Nederland</Nm>
                            </Cdtr>
                            <CdtrAcct>
                                <Id>
                                    {$accountIdXml}
                                </Id>
                            </CdtrAcct>
                        </RltdPties>
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
function parseCamt053WithCounterpartyAccount(Camt053Adapter $adapter, AccountResolver $accounts, string $accountIdXml): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'camt-othr-').'.xml';
    file_put_contents($tmp, camt053DocWithCounterpartyAccount($accountIdXml));

    try {
        /** @var list<SourceTransactionDto> $dtos */
        $dtos = iterator_to_array($adapter->parse($tmp, $accounts), preserve_keys: false);

        return $dtos;
    } finally {
        @unlink($tmp);
    }
}

beforeEach(function (): void {
    $this->accounts = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $this->adapter = $this->app->make(Camt053Adapter::class);
});

it('keeps a counterparty account identified by Othr rather than IBAN', function (): void {
    $dtos = parseCamt053WithCounterpartyAccount(
        $this->adapter,
        $this->accounts,
        '<Othr><Id>ICS-CARD</Id></Othr>',
    );

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->counterpartyName)->toBe('ICS Cards Nederland');
    expect($dtos[0]->counterpartyIban)->toBe('ICS-CARD');
});

it('keeps an Othr identifier carrying a scheme name and issuer', function (): void {
    $dtos = parseCamt053WithCounterpartyAccount(
        $this->adapter,
        $this->accounts,
        '<Othr><Id>5401230000000000</Id><SchmeNm><Cd>CUID</Cd></SchmeNm><Issr>ICS</Issr></Othr>',
    );

    expect($dtos[0]->counterpartyIban)->toBe('5401230000000000');
});

it('still reads the IBAN branch of the choice', function (): void {
    $dtos = parseCamt053WithCounterpartyAccount(
        $this->adapter,
        $this->accounts,
        '<IBAN>NL08ABNA0526650664</IBAN>',
    );

    expect($dtos[0]->counterpartyIban)->toBe('NL08ABNA0526650664');
});

it('answers null for an Othr branch whose identifier is blank, not an empty identifier', function (): void {
    $dtos = parseCamt053WithCounterpartyAccount(
        $this->adapter,
        $this->accounts,
        '<Othr><Id>   </Id></Othr>',
    );

    expect($dtos[0]->counterpartyIban)->toBeNull();
});

it('answers null when the counterparty carries no account at all', function (): void {
    $dtos = parseCamt053WithCounterpartyAccount(
        $this->adapter,
        $this->accounts,
        '<Othr><Id>UNUSED</Id></Othr>',
    );

    expect($dtos[0]->counterpartyIban)->not->toBeNull();

    $withoutAccount = parseCamt053WithCounterpartyAccount(
        $this->adapter,
        $this->accounts,
        '',
    );

    expect($withoutAccount[0]->counterpartyIban)->toBeNull();
    expect($withoutAccount[0]->counterpartyName)->toBe('ICS Cards Nederland');
});
