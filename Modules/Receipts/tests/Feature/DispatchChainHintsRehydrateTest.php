<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Internal\Listeners\DispatchChainHintsFromReceipt;
use Modules\Receipts\Public\Dto\ChainHintPayload\RefundOfPayload;
use Modules\Receipts\Public\Events\ChainHintDetected;

// The end-to-end fixtures only ever carry a funded_by_card hint, so these cover
// the refund_of arm and the malformed-hint guards, which have to drop silently
// rather than dispatch.

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
    $this->fixtureAccount = $seeded['account'];
});

function seedReceiptTransaction(User $user, int $accountId, array $chainHints): Transaction
{
    static $idx = 0;
    $idx++;

    $run = ImportRun::create([
        'user_id' => $user->id,
        'source_format' => 'eml',
        'raw_file_path' => '/tmp/rehydrate-'.$idx.'.eml',
        'sha256' => str_pad((string) $idx, 64, 'a', STR_PAD_LEFT),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'confirmed',
    ]);

    return Transaction::create([
        'user_id' => $user->id,
        'account_id' => $accountId,
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1299,
        'currency' => 'EUR',
        'settled_amount_minor' => -1299,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Fixture Merchant',
        'counterparty_normalized' => 'fixture merchant',
        'normalization_version' => 3,
        'raw_payload' => ['chain_hints' => $chainHints],
        'source_format' => 'eml',
        'import_run_id' => $run->id,
        'source_row_index' => $idx,
        'fingerprint' => str_pad((string) $idx, 64, 'a', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);
}

it('rehydrates a refund_of hint into a RefundOfPayload and dispatches ChainHintDetected', function (): void {
    Event::fake([ChainHintDetected::class]);

    $tx = seedReceiptTransaction($this->fixtureUser, $this->fixtureAccount->id, [
        ['hint_type' => 'refund_of', 'original_reference_id' => 'ORIG-REF-123', 'evidence' => 'refund of ORIG-REF-123'],
    ]);
    // The listener needs the cast to have round-tripped back into an array.
    expect($tx->raw_payload)->toBeArray();

    $this->app->make(DispatchChainHintsFromReceipt::class)->handle(new TransactionImported(
        transaction: $tx,
        user: $this->fixtureUser,
    ));

    Event::assertDispatched(ChainHintDetected::class, function (ChainHintDetected $event) use ($tx): bool {
        return $event->sourceTransactionId === $tx->id
            && $event->hintType === 'refund_of'
            && $event->hintPayload instanceof RefundOfPayload
            && $event->hintPayload->originalReferenceId === 'ORIG-REF-123'
            && $event->evidence === 'refund of ORIG-REF-123'
            && $event->userId === $this->fixtureUser->id;
    });
});

it('drops malformed hints (missing card_last4 and missing original_reference_id) without dispatching', function (): void {
    Event::fake([ChainHintDetected::class]);

    $tx = seedReceiptTransaction($this->fixtureUser, $this->fixtureAccount->id, [
        ['hint_type' => 'funded_by_card', 'card_last4' => '', 'evidence' => 'x'],
        ['hint_type' => 'refund_of', 'original_reference_id' => '', 'evidence' => 'y'],
    ]);

    $this->app->make(DispatchChainHintsFromReceipt::class)->handle(new TransactionImported(
        transaction: $tx,
        user: $this->fixtureUser,
    ));

    Event::assertNotDispatched(ChainHintDetected::class);
});
