<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// A converting bank states a batch child twice: TxDtls/Amt is what the euro
// account moved by, AmtDtls/TxAmt the dollars the transaction was made in. The
// adapter preferred the second and published no settled leg, so the row landed
// -10000 USD against a EUR statement and the two rows of this one entry could
// not be added together at all -- the statement closes 102.50 lower and the
// file said 100.00 USD plus 10.00 EUR.
function convertedChildCamtDoc(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr><MsgId>CONVERTED-BATCH</MsgId><CreDtTm>2026-04-01T09:00:00+02:00</CreDtTm></GrpHdr>
        <Stmt>
            <Id>CONVERTED-BATCH-STMT</Id>
            <CreDtTm>2026-04-01T09:00:00+02:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">1000.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">897.50</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-02</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">102.50</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-04-02</Dt></BookgDt><ValDt><Dt>2026-04-02</Dt></ValDt>
                <NtryDtls>
                    <TxDtls>
                        <Refs><EndToEndId>CONVERTED-1</EndToEndId></Refs>
                        <AmtDtls>
                            <TxAmt>
                                <Amt Ccy="USD">100.00</Amt>
                            </TxAmt>
                        </AmtDtls>
                        <Amt Ccy="EUR">92.50</Amt>
                        <RltdPties><Cdtr><Nm>Dollar Merchant</Nm></Cdtr></RltdPties>
                    </TxDtls>
                    <TxDtls>
                        <Refs><EndToEndId>CONVERTED-2</EndToEndId></Refs>
                        <Amt Ccy="EUR">10.00</Amt>
                        <RltdPties><Cdtr><Nm>Euro Merchant</Nm></Cdtr></RltdPties>
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
function convertedChildParse(Camt053Adapter $adapter, AccountResolver $resolver): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'camt-converted-batch-').'.xml';
    file_put_contents($tmp, convertedChildCamtDoc());

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

it('keeps the dollars as the native leg and the euros the account moved by as the settled one', function (): void {
    $dtos = convertedChildParse($this->adapter, $this->resolver);

    $converted = $dtos[0];

    expect($converted->sourceRef)->toBe('CONVERTED-1')
        ->and($converted->amountMinor)->toBe(-10000)
        ->and($converted->currency)->toBe('USD')
        ->and($converted->settledAmountMinor)->toBe(-9250)
        ->and($converted->settledCurrency)->toBe('EUR');
});

it('leaves a child the bank did not convert on one leg', function (): void {
    $dtos = convertedChildParse($this->adapter, $this->resolver);

    $plain = $dtos[1];

    expect($plain->sourceRef)->toBe('CONVERTED-2')
        ->and($plain->amountMinor)->toBe(-1000)
        ->and($plain->currency)->toBe('EUR')
        ->and($plain->settledAmountMinor)->toBeNull()
        ->and($plain->settledCurrency)->toBeNull();
});

it('closes the statement on the sum of what the account actually moved by', function (): void {
    $dtos = convertedChildParse($this->adapter, $this->resolver);

    // Every leg the account moved by, in the account's own currency: the
    // converted child contributes its settled euros, the plain child its only
    // figure. 1000.00 - 102.50 is the closing balance the file states.
    $settled = array_map(
        static fn (SourceTransactionDto $d): int => $d->settledAmountMinor ?? $d->amountMinor,
        $dtos,
    );
    $currencies = array_map(
        static fn (SourceTransactionDto $d): string => $d->settledCurrency ?? $d->currency,
        $dtos,
    );

    expect($currencies)->toBe(['EUR', 'EUR'])
        ->and(array_sum($settled))->toBe(-10250)
        ->and(100000 + array_sum($settled))->toBe(89750);
});
