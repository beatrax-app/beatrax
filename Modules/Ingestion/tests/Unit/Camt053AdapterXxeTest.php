<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;

beforeEach(function (): void {
    $this->resolver = new class implements AccountResolver
    {
        /** @var array<int, string> */
        public array $askedFor = [];

        public function resolve(string $iban): AccountResolution
        {
            $this->askedFor[] = $iban;

            return AccountResolution::unknown($iban);
        }
    };

    $this->adapter = $this->app->make(Camt053Adapter::class);
});

it('still parses a legitimate CAMT.053 export after XXE hardening', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-camt053-sample-1.xml'), $this->resolver),
        preserve_keys: false,
    );

    expect($dtos)->not->toBeEmpty();
    foreach ($dtos as $dto) {
        expect($dto)->toBeInstanceOf(SourceTransactionDto::class);
        expect($dto->ownIban)->toBe('NL57ASNB0123456789');
    }
})->group('phase-2');

it('does not leak a file:// external entity secret into any parsed field (XXE denied)', function (): void {
    // This string can only reach a parsed field if libxml resolved the external
    // entity and substituted the file's contents.
    $secret = 'XXE-SECRET-'.uniqid('', true);
    $secretFile = tempnam(sys_get_temp_dir(), 'camt-xxe-secret-').'.txt';
    file_put_contents($secretFile, $secret);

    // The namespace has to be a real one or the sniffer rejects the file before
    // the hardened reader is ever reached. `&ent;` sits in the unstructured
    // remittance, which surfaces as $dto->description.
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE Document [
    <!ENTITY ent SYSTEM "file://{$secretFile}">
]>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr>
            <MsgId>CAMT053-XXE</MsgId>
            <CreDtTm>2026-05-12T21:12:27.273942228+02:00</CreDtTm>
        </GrpHdr>
        <Stmt>
            <Id>CAMT053-XXE-0001</Id>
            <ElctrncSeqNb>1</ElctrncSeqNb>
            <CreDtTm>2026-05-12T21:12:27.229917934+02:00</CreDtTm>
            <Acct>
                <Id><IBAN>NL57ASNB0123456789</IBAN></Id>
                <Ccy>EUR</Ccy>
                <Svcr><FinInstnId><BIC>ASNBNL21</BIC></FinInstnId></Svcr>
            </Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">100.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">100.00</Amt>
                <CdtDbtInd>CRDT</CdtDbtInd>
                <Dt><Dt>2026-02-28</Dt></Dt>
            </Bal>
            <Ntry>
                <NtryRef>XXE-1</NtryRef>
                <Amt Ccy="EUR">1.23</Amt>
                <CdtDbtInd>DBIT</CdtDbtInd>
                <Sts>BOOK</Sts>
                <BookgDt><Dt>2026-02-02</Dt></BookgDt>
                <ValDt><Dt>2026-02-02</Dt></ValDt>
                <BkTxCd>
                    <Domn><Cd>PMNT</Cd><Fmly><Cd>RDDT</Cd><SubFmlyCd>ESDD</SubFmlyCd></Fmly></Domn>
                </BkTxCd>
                <NtryDtls>
                    <TxDtls>
                        <Refs><EndToEndId>XXE-E2E</EndToEndId></Refs>
                        <RmtInf>
                            <Ustrd>&ent;</Ustrd>
                        </RmtInf>
                    </TxDtls>
                </NtryDtls>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;

    $tmp = tempnam(sys_get_temp_dir(), 'camt-xxe-').'.xml';
    file_put_contents($tmp, $xml);

    try {
        $leaked = false;

        try {
            $dtos = iterator_to_array(
                $this->adapter->parse($tmp, $this->resolver),
                preserve_keys: false,
            );

            // The JSON encode is the catch-all: the sentinel must not reach any
            // field, not just description.
            $encoded = (string) json_encode($dtos);
            if (str_contains($encoded, $secret)) {
                $leaked = true;
            }

            foreach ($dtos as $dto) {
                if ($dto->description !== null && str_contains($dto->description, $secret)) {
                    $leaked = true;
                }
            }
        } catch (InvalidAmountException $e) {
            // A refusal is equally acceptable, as long as the secret did not
            // ride out on the exception message.
            expect($e->getMessage())->not->toContain($secret);
        }

        expect($leaked)->toBeFalse();
    } finally {
        @unlink($tmp);
        @unlink($secretFile);
    }
})->group('phase-2');
