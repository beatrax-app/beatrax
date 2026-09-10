<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;

// A PayPal total the message put no mark of any kind against falls back to the
// owner's reporting currency. `ProcessFetchedInboxMessagesJob` and
// `ScanInboxDropFolderJob` run in a queue worker, where nobody is signed in and
// `BaseCurrency::code()` answers with the INSTALL default — so the figure was
// booked as euro for a reader who reports in dollars, and the same message read
// two ways depending on which door it came through.

function unmarkedPaypalEml(): string
{
    return "From: service@paypal.com\r\n"
        ."To: reader@example.test\r\n"
        ."Subject: Receipt\r\n"
        ."Date: Sun, 17 May 2026 09:42:13 +0200\r\n"
        ."Message-ID: <unmarked-total@example.test>\r\n"
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."\r\n"
        .implode("\n", [
            'Merchant: Nintendo',
            'Amount: 12.99',
            'Transaction ID: PAYPALTXNUSD00001',
        ]);
}

it('denominates an unmarked total in the owner\'s reporting currency with nobody signed in', function (): void {
    $owner = User::create([
        'username' => 'receipt-owner',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => Currency::Usd->value,
    ]);

    expect(auth()->user())->toBeNull();

    $outcome = app(RecordReceipt::class)(unmarkedPaypalEml(), $owner, 'unmarked.eml');

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($outcome->parsed?->currency)->toBe(Currency::Usd->value)
        ->and($outcome->parsed?->amountMinor)->toBe(-1299);
});
