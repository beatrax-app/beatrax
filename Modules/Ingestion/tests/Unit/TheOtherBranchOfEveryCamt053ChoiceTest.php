<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

// Siblings of the dropped CdtrAcct/Id/Othr identifier: each is an ISO 20022
// element with more than one legal shape, where reading one shape answered
// "absent" — or worse — for the rest.

/**
 * @return list<SourceTransactionDto>
 */
function parseCamt053Choice(string $quote, string $bkTxCd, string $rmtInf): array
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns={$quote}urn:iso:std:iso:20022:tech:xsd:camt.053.001.02{$quote}>
    <BkToCstmrStmt>
        <GrpHdr><MsgId>CHOICE</MsgId><CreDtTm>2026-05-19T08:00:00+02:00</CreDtTm></GrpHdr>
        <Stmt>
            <Id>CHOICE-STMT</Id><CreDtTm>2026-05-19T08:00:00+02:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Ntry>
                <Amt Ccy="EUR">10.00</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-05-19</Dt></BookgDt><ValDt><Dt>2026-05-19</Dt></ValDt>
                {$bkTxCd}
                <NtryDtls><TxDtls>{$rmtInf}</TxDtls></NtryDtls>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;

    $accounts = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    $tmp = tempnam(sys_get_temp_dir(), 'camt-choice-').'.xml';
    file_put_contents($tmp, $xml);

    try {
        /** @var list<SourceTransactionDto> $dtos */
        $dtos = iterator_to_array(app(Camt053Adapter::class)->parse($tmp, $accounts), preserve_keys: false);

        return $dtos;
    } finally {
        @unlink($tmp);
    }
}

it('accepts a namespace declared with single quotes, which XML allows as much as double', function (): void {
    $dtos = parseCamt053Choice("'", '', '');

    expect($dtos)->toHaveCount(1);
    expect($dtos[0]->ownIban)->toBe('NL57ASNB0123456789');
});

it('reads a bank transaction code whose domain carries no family instead of dying on it', function (): void {
    $dtos = parseCamt053Choice('"', '<BkTxCd><Domn><Cd>PMNT</Cd></Domn></BkTxCd>', '');

    expect($dtos[0]->rawPayload['sepa']['btc'])->toBe([
        'domain' => 'PMNT',
        'family' => null,
        'subFamily' => null,
        'proprietary' => null,
    ]);
});

it('still reads the whole domain code when the family is there', function (): void {
    $dtos = parseCamt053Choice(
        '"',
        '<BkTxCd><Domn><Cd>PMNT</Cd><Fmly><Cd>RCDT</Cd><SubFmlyCd>ESCT</SubFmlyCd></Fmly></Domn></BkTxCd>',
        '',
    );

    expect($dtos[0]->rawPayload['sepa']['btc'])->toBe([
        'domain' => 'PMNT',
        'family' => 'RCDT',
        'subFamily' => 'ESCT',
        'proprietary' => null,
    ]);
});

it('keeps the structured half of RmtInf, which the unstructured reader answers null for', function (): void {
    $dtos = parseCamt053Choice(
        '"',
        '',
        '<RmtInf><Strd><CdtrRefInf><Ref>RF18539007547034</Ref></CdtrRefInf><AddtlRmtInf>Invoice 42</AddtlRmtInf></Strd></RmtInf>',
    );

    expect($dtos[0]->description)->toBeNull();
    expect($dtos[0]->rawPayload['sepa']['remittanceStructured'])->toBe([
        ['ref' => 'RF18539007547034', 'additional' => 'Invoice 42'],
    ]);
});

it('leaves the structured list empty when the remittance is unstructured', function (): void {
    $dtos = parseCamt053Choice('"', '', '<RmtInf><Ustrd>Hello</Ustrd></RmtInf>');

    expect($dtos[0]->description)->toBe('Hello');
    expect($dtos[0]->rawPayload['sepa']['remittanceStructured'])->toBe([]);
});
