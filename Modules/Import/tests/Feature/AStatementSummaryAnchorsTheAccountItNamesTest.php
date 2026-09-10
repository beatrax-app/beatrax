<?php

declare(strict_types=1);

use Modules\Import\Public\Contracts\RunsImports;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\StatementSummary;
use Modules\Ledger\Public\Enums\Currency;

// One CAMT.053 message may carry a statement per account, and the adapter
// publishes the FIRST statement's metadata deliberately while yielding every
// statement's rows under their own IBANs. The pipeline stamped that one summary
// with whichever account the LAST row happened to resolve to, so a two-account
// export filed the first account's opening balance against the second: the
// summary named one IBAN in iban_owner and a different account in account_id,
// and the anchor derived from it started the wrong account at the wrong money.
// The IBAN the summary states is now the one it is filed under, and an account
// this file said nothing about is left unanchored rather than anchored wrongly.

function twoAccountCamtDocument(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
    <BkToCstmrStmt>
        <GrpHdr>
            <MsgId>TWO-ACCOUNTS</MsgId>
            <CreDtTm>2026-05-02T09:00:00+02:00</CreDtTm>
        </GrpHdr>
        <Stmt>
            <Id>TWO-ACCOUNTS-ASN</Id>
            <ElctrncSeqNb>41</ElctrncSeqNb>
            <CreDtTm>2026-05-02T09:00:00+02:00</CreDtTm>
            <Acct><Id><IBAN>NL57ASNB0123456789</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">100.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">150.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-30</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">50.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-04-02</Dt></BookgDt><ValDt><Dt>2026-04-02</Dt></ValDt>
                <NtryDtls><TxDtls>
                    <Refs><EndToEndId>ASN-APRIL-CREDIT</EndToEndId></Refs>
                    <RltdPties><Dbtr><Nm>Payroll BV</Nm></Dbtr></RltdPties>
                    <RmtInf><Ustrd>Salaris april</Ustrd></RmtInf>
                </TxDtls></NtryDtls>
            </Ntry>
        </Stmt>
        <Stmt>
            <Id>TWO-ACCOUNTS-ABN</Id>
            <ElctrncSeqNb>17</ElctrncSeqNb>
            <CreDtTm>2026-05-02T09:00:00+02:00</CreDtTm>
            <Acct><Id><IBAN>NL91ABNA0417164300</IBAN></Id><Ccy>EUR</Ccy></Acct>
            <Bal>
                <Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">5000.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-01</Dt></Dt>
            </Bal>
            <Bal>
                <Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp>
                <Amt Ccy="EUR">5200.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-04-30</Dt></Dt>
            </Bal>
            <Ntry>
                <Amt Ccy="EUR">200.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts>
                <BookgDt><Dt>2026-04-03</Dt></BookgDt><ValDt><Dt>2026-04-03</Dt></ValDt>
                <NtryDtls><TxDtls>
                    <Refs><EndToEndId>ABN-APRIL-CREDIT</EndToEndId></Refs>
                    <RltdPties><Dbtr><Nm>Rente BV</Nm></Dbtr></RltdPties>
                    <RmtInf><Ustrd>Rente april</Ustrd></RmtInf>
                </TxDtls></NtryDtls>
            </Ntry>
        </Stmt>
    </BkToCstmrStmt>
</Document>
XML;
}

beforeEach(function (): void {
    $this->freezeClockOnTheStatementFixtureWindow();
    $seeded = $this->seedFixtureUserAndAccount();
    $this->asnAccount = $seeded['account'];

    // The second statement's account, and the one the last row of the file
    // resolves to — which is what used to decide where the summary was filed.
    $this->abnAccount = Account::create([
        'user_id' => $this->fixtureUser->id,
        'name' => 'ABN Fixture Account',
        'slug' => 'abn-fixture',
        'kind' => 'abn',
        'iban' => 'NL91ABNA0417164300',
        'default_currency' => Currency::Eur->value,
    ]);

    $this->actingAs($this->fixtureUser);
    $this->importer = $this->app->make(RunsImports::class);

    $path = tempnam(sys_get_temp_dir(), 'two-accounts-').'.xml';
    file_put_contents($path, twoAccountCamtDocument());
    register_shutdown_function(static function () use ($path): void {
        @unlink($path);
    });
    $this->camtPath = $path;
});

it('files the summary under the account its own IBAN names', function (): void {
    $result = $this->importer->runAndConfirm($this->camtPath, 'camt053', $this->fixtureUser);

    expect($result->errors)->toBe(0);

    /** @var StatementSummary|null $summary */
    $summary = StatementSummary::query()
        ->where('import_run_id', $result->importRunId)
        ->first();

    expect($summary)->not->toBeNull();
    /** @var StatementSummary $summary */
    expect($summary->iban_owner)->toBe('NL57ASNB0123456789');
    expect($summary->account_id)->toBe($this->asnAccount->id);
    expect($summary->opening_balance_minor)->toBe(10000);
});

it('starts the account the summary describes at that summary opening balance', function (): void {
    $this->importer->runAndConfirm($this->camtPath, 'camt053', $this->fixtureUser);

    /** @var Account $asn */
    $asn = $this->asnAccount->fresh();

    expect($asn->starting_balance_minor)->toBe(10000);
    expect($asn->starting_balance_date?->toDateString())->toBe('2026-04-01');
});

// The other account is in the file and its rows are imported, but no summary
// describes it, so it has no anchor. That is the point: an account without one
// reads as unanchored everywhere, while one anchored off a statement that was
// never about it is wrong on every balance and says so nowhere.
it('leaves the account no summary describes unanchored rather than wrong', function (): void {
    $this->importer->runAndConfirm($this->camtPath, 'camt053', $this->fixtureUser);

    /** @var Account $abn */
    $abn = $this->abnAccount->fresh();

    expect($abn->starting_balance_minor)->toBeNull();
    expect($abn->starting_balance_date)->toBeNull();
});

it('imports the rows of both statements even though one summary is published', function (): void {
    $result = $this->importer->runAndConfirm($this->camtPath, 'camt053', $this->fixtureUser);

    expect($result->inserted)->toBe(2);
    expect(StatementSummary::query()->where('import_run_id', $result->importRunId)->count())->toBe(1);
});
