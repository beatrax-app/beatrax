<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Chains\Internal\Listeners\CreateChainLinkFromHint;
use Modules\Chains\Internal\Presentation\HintEvidenceSummary;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Enums\ChainHintType;
use Modules\Receipts\Public\Events\ChainHintDetected;

// Writer and reader are exercised end to end on purpose: the evidence bag is
// an untyped JSON column, so a key only one side spells is invisible until a
// reader that ran after the real writer comes up empty.

function hewUser(): User
{
    return User::query()->create([
        'username' => 'hew-user',
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

function hewTransaction(User $user): Transaction
{
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'hew card',
        'slug' => 'hew-card',
        'kind' => 'ics_card',
        'iban' => 'NL16ASNB0000000042',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'eml',
        'raw_file_path' => 'hew.eml',
        'sha256' => str_repeat('e', 64),
        'status' => 'confirmed',
        'uploaded_at' => '2026-05-15 12:00:00',
    ]);

    return Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1000,
        'currency' => 'EUR',
        'settled_amount_minor' => -1000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'hew merchant',
        'counterparty_normalized' => 'hew-merchant',
        'normalization_version' => 1,
        'source_format' => 'eml',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => str_pad('hewfp', 64, 'h', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

it('renders the card line for a hint the production writer actually wrote', function (): void {
    $user = hewUser();
    $transaction = hewTransaction($user);

    /** @var CreateChainLinkFromHint $listener */
    $listener = $this->app->make(CreateChainLinkFromHint::class);
    $listener->handle(new ChainHintDetected(
        sourceTransactionId: (int) $transaction->id,
        hintType: ChainHintType::FundedByCard,
        hintPayload: new FundedByCardPayload(cardLast4: '1234'),
        evidence: 'ICS receipt',
        userId: (int) $user->id,
    ));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $evidenceJson = (string) $db->connection()->table('chain_links')
        ->where('user_id', $user->id)
        ->where('kind', ChainLinkKind::FundedByCardHint->value)
        ->value('evidence');

    $lines = (new HintEvidenceSummary)->forHint(
        ChainLinkKind::FundedByCardHint->value,
        $evidenceJson,
        'EUR',
    );

    expect($lines)->toBe(['Card ending in 1234']);
});
