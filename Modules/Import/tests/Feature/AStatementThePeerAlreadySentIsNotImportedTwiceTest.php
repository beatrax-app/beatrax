<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Import\Internal\Pipeline\Stages\FingerprintStage;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Ledger\Public\Services\FingerprintComposer;
use Modules\Sync\Public\Events\PeerRowsApplied;

// An imported row deduplicates on the import fingerprint, and sync inherits
// that rather than reinventing it. The digest is composed over account_id,
// which the applier translates on arrival, so the property holds only because
// RederiveFingerprintOnMergedRows recomposes the digest against the local id.
/**
 * @link ../../../../.docs/features/sync/architecture.md#one-announcement-is-not-one-op
 */
function peerStatementRow(User $user, int $accountId, int $amountMinor): CanonicalTransaction
{
    return new CanonicalTransaction(
        userId: $user->id,
        accountId: $accountId,
        type: 'expense',
        postedAt: CarbonImmutable::parse('2026-02-02'),
        bookedAt: CarbonImmutable::parse('2026-02-02 00:00:00'),
        valueDate: CarbonImmutable::parse('2026-02-02'),
        amountMinor: $amountMinor,
        currency: 'EUR',
        settledAmountMinor: $amountMinor,
        settledCurrency: 'EUR',
        counterpartyName: 'Albert Heijn',
        counterpartyIban: null,
        counterpartyNormalized: 'albert heijn',
        normalizationVersion: 4,
        description: null,
        categoryId: null,
        sourceFormat: 'asn-csv',
        importRunId: 0,
        sourceRowIndex: 1,
        sourceRef: null,
    );
}

// The row as the applier leaves it: account_id already rewritten to the id
// THIS device uses, the digest still the one the sender composed over its own.
function landPeerCreate(User $user, int $localAccountId, int $senderAccountId, int $amountMinor): int
{
    /** @var FingerprintComposer $composer */
    $composer = app(FingerprintComposer::class);
    $suffix = bin2hex(random_bytes(4));

    $runId = (int) DB::table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/peer-'.$suffix.'.csv',
        'sha256' => hash('sha256', 'peer-'.$suffix),
        'uploaded_at' => '2026-02-02 12:00:00',
        'status' => 'confirmed',
        'created_at' => '2026-02-02 12:00:00',
        'updated_at' => '2026-02-02 12:00:00',
    ]);

    $sent = peerStatementRow($user, $senderAccountId, $amountMinor);

    return (int) DB::table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $localAccountId,
        'type' => 'expense',
        'posted_at' => $sent->postedAt->toDateString(),
        'booked_at' => $sent->bookedAt->toDateTimeString(),
        'value_date' => $sent->valueDate->toDateString(),
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => $sent->counterpartyName,
        'counterparty_normalized' => $sent->counterpartyNormalized,
        'normalization_version' => 4,
        'source_format' => 'asn-csv',
        'import_run_id' => $runId,
        'source_row_index' => 1,
        'occurrence_ordinal' => 0,
        'fingerprint' => $composer->compose($sent),
        'fingerprint_version' => $composer->version(),
        'status' => 'cleared',
        'created_at' => '2026-02-02 12:00:00',
        'updated_at' => '2026-02-02 12:00:00',
    ]);
}

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->account = $seeded['account'];
    $this->stage = $this->app->make(FingerprintStage::class);
    $this->senderAccountId = (int) $this->account->id + 1000;
});

// The control that makes the next case mean something: while the digest still
// describes the sender's row, this device does not recognise the statement it
// is already holding, and the import books a second copy of it.
it('does not recognise the row the peer sent while its digest still names the sender account', function (): void {
    landPeerCreate($this->fixtureUser, (int) $this->account->id, $this->senderAccountId, -115413);

    $incoming = peerStatementRow($this->fixtureUser, (int) $this->account->id, -115413);

    expect($this->stage->classify($incoming, $this->fixtureUser)->status())
        ->toBe(PreviewRowStatus::NewRow);
});

it('recognises a statement row the peer already sent as a duplicate of it', function (): void {
    $txId = landPeerCreate($this->fixtureUser, (int) $this->account->id, $this->senderAccountId, -115413);

    app('events')->dispatch(new PeerRowsApplied(
        userId: (int) $this->fixtureUser->id,
        created: ['transactions' => [$txId]],
    ));

    $incoming = peerStatementRow($this->fixtureUser, (int) $this->account->id, -115413);

    expect($this->stage->classify($incoming, $this->fixtureUser)->status())
        ->toBe(PreviewRowStatus::Duplicate);
});

// The positive control. Realigning the digest must not make the ledger answer
// "duplicate" to a row it has never seen, which is how a false negative gets
// traded for a false positive that silently drops a transaction.
it('still calls a genuinely different transaction new after the digest is realigned', function (): void {
    $txId = landPeerCreate($this->fixtureUser, (int) $this->account->id, $this->senderAccountId, -115413);

    app('events')->dispatch(new PeerRowsApplied(
        userId: (int) $this->fixtureUser->id,
        created: ['transactions' => [$txId]],
    ));

    $different = peerStatementRow($this->fixtureUser, (int) $this->account->id, -115414);

    expect($this->stage->classify($different, $this->fixtureUser)->status())
        ->toBe(PreviewRowStatus::NewRow);
});
