<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// A batch's children ARE the entry, restated one payment at a time. Where the
// two disagree the entry is the figure the closing balance was computed from,
// and the children are a reading of it that came up short — so the entry is
// what gets booked, and the difference is not spent or invented on the way.
function batchArithmeticDoc(string $opening, string $closing, string $entry): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr><MsgId>BATCH-ARITH</MsgId><CreDtTm>2026-03-01T09:00:00+01:00</CreDtTm></GrpHdr>
        <Stmt>
            <Id>BATCH-ARITH-STMT</Id>
            <CreDtTm>2026-03-01T09:00:00+01:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">{$opening}</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-02-28</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">{$closing}</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-03-01</Dt></Dt>
            </Bal>
{$entry}
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;
}

// Two <NtryDtls> blocks under one entry. Only the first is read back, so the
// 30.00 in the second reached nothing and the two rows that did arrive spent
// half of a 60.00 booking.
function batchArithmeticSplitBlocksEntry(): string
{
    return <<<'XML'
            <Ntry>
                <Amt Ccy="EUR">60.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-03-01</Dt></BookgDt><ValDt><Dt>2026-03-01</Dt></ValDt>
                <NtryDtls>
                    <TxDtls><Refs><EndToEndId>SPLIT-A</EndToEndId></Refs><Amt Ccy="EUR">20.00</Amt></TxDtls>
                    <TxDtls><Refs><EndToEndId>SPLIT-B</EndToEndId></Refs><Amt Ccy="EUR">10.00</Amt></TxDtls>
                </NtryDtls>
                <NtryDtls>
                    <TxDtls><Refs><EndToEndId>SPLIT-C</EndToEndId></Refs><Amt Ccy="EUR">30.00</Amt></TxDtls>
                </NtryDtls>
            </Ntry>
XML;
}

// A batch collection whose children carry references and parties but state no
// figure of their own: every child then took the entry's, so one 90.00 debit
// was booked three times.
function batchArithmeticAmountlessChildrenEntry(): string
{
    return <<<'XML'
            <Ntry>
                <Amt Ccy="EUR">90.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-03-01</Dt></BookgDt><ValDt><Dt>2026-03-01</Dt></ValDt>
                <NtryDtls>
                    <Btch><NbOfTxs>3</NbOfTxs></Btch>
                    <TxDtls><Refs><EndToEndId>NOAMT-A</EndToEndId></Refs><RltdPties><Cdtr><Nm>Child A</Nm></Cdtr></RltdPties></TxDtls>
                    <TxDtls><Refs><EndToEndId>NOAMT-B</EndToEndId></Refs><RltdPties><Cdtr><Nm>Child B</Nm></Cdtr></RltdPties></TxDtls>
                    <TxDtls><Refs><EndToEndId>NOAMT-C</EndToEndId></Refs><RltdPties><Cdtr><Nm>Child C</Nm></Cdtr></RltdPties></TxDtls>
                </NtryDtls>
            </Ntry>
XML;
}

// A child whose only figure is the currency the payment was made in, with no
// restatement of what the account moved by. Adding it to a euro sibling is
// arithmetic across two denominations, so there is nothing to check by.
function batchArithmeticForeignChildEntry(): string
{
    return <<<'XML'
            <Ntry>
                <Amt Ccy="EUR">60.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-03-01</Dt></BookgDt><ValDt><Dt>2026-03-01</Dt></ValDt>
                <NtryDtls>
                    <TxDtls><Refs><EndToEndId>FOREIGN-A</EndToEndId></Refs><AmtDtls><TxAmt><Amt Ccy="USD">55.00</Amt></TxAmt></AmtDtls></TxDtls>
                    <TxDtls><Refs><EndToEndId>FOREIGN-B</EndToEndId></Refs><Amt Ccy="EUR">10.00</Amt></TxDtls>
                </NtryDtls>
            </Ntry>
XML;
}

// The batch that does add up, child by child, under the direction each child
// states for itself: 50.00 out, 25.00 back, 75.00 out, on a 100.00 entry.
function batchArithmeticBalancedEntry(): string
{
    return <<<'XML'
            <Ntry>
                <Amt Ccy="EUR">100.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-03-01</Dt></BookgDt><ValDt><Dt>2026-03-01</Dt></ValDt>
                <NtryDtls>
                    <TxDtls><Refs><EndToEndId>BAL-A</EndToEndId></Refs><Amt Ccy="EUR">50.00</Amt></TxDtls>
                    <TxDtls><Refs><EndToEndId>BAL-B</EndToEndId></Refs><Amt Ccy="EUR">25.00</Amt><CdtDbtInd>CRDT</CdtDbtInd></TxDtls>
                    <TxDtls><Refs><EndToEndId>BAL-C</EndToEndId></Refs><Amt Ccy="EUR">75.00</Amt><CdtDbtInd>DBIT</CdtDbtInd></TxDtls>
                </NtryDtls>
            </Ntry>
XML;
}

/**
 * @return list<SourceTransactionDto>
 */
function batchArithmeticParse(Camt053Adapter $adapter, AccountResolver $resolver, string $xml): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'camt-batch-arith-').'.xml';
    file_put_contents($tmp, $xml);

    try {
        return iterator_to_array($adapter->parse($tmp, $resolver), preserve_keys: false);
    } finally {
        @unlink($tmp);
    }
}

/**
 * @param  list<SourceTransactionDto>  $dtos
 * @return list<int>
 */
function batchArithmeticAmounts(array $dtos): array
{
    return array_map(static fn (SourceTransactionDto $d): int => $d->amountMinor, $dtos);
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

it('books the whole entry once when its children are only part of it', function (): void {
    $dtos = batchArithmeticParse(
        $this->adapter,
        $this->resolver,
        batchArithmeticDoc('1000.00', '940.00', batchArithmeticSplitBlocksEntry()),
    );

    expect(batchArithmeticAmounts($dtos))->toBe([-6000]);
});

it('books the whole entry once when no child states a figure of its own', function (): void {
    $dtos = batchArithmeticParse(
        $this->adapter,
        $this->resolver,
        batchArithmeticDoc('1000.00', '910.00', batchArithmeticAmountlessChildrenEntry()),
    );

    expect(batchArithmeticAmounts($dtos))->toBe([-9000]);
});

it('books the whole entry once when a child names only the currency it was paid in', function (): void {
    $dtos = batchArithmeticParse(
        $this->adapter,
        $this->resolver,
        batchArithmeticDoc('1000.00', '940.00', batchArithmeticForeignChildEntry()),
    );

    expect(batchArithmeticAmounts($dtos))->toBe([-6000])
        ->and($dtos[0]->currency)->toBe('EUR');
});

it('leaves a statement whose entry was booked whole adding up', function (): void {
    batchArithmeticParse(
        $this->adapter,
        $this->resolver,
        batchArithmeticDoc('1000.00', '940.00', batchArithmeticSplitBlocksEntry()),
    );
    $meta = $this->adapter->statementMetadata();

    expect($meta->entryCount)->toBe(1)
        ->and($meta->extras)->not->toHaveKey('statementDifferenceMinor');
});

it('still books a batch child by child where the children add up to their entry', function (): void {
    $dtos = batchArithmeticParse(
        $this->adapter,
        $this->resolver,
        batchArithmeticDoc('1000.00', '900.00', batchArithmeticBalancedEntry()),
    );

    expect(batchArithmeticAmounts($dtos))->toBe([-5000, 2500, -7500])
        ->and(array_map(static fn (SourceTransactionDto $d): ?string => $d->sourceRef, $dtos))
        ->toBe(['BAL-A', 'BAL-B', 'BAL-C']);
});
