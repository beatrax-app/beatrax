<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\InboxMessageStatus;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;

// A matcher that claimed a message and then withheld it, and a message no
// matcher claimed at all, are two different answers. The registry stamps the
// answering matcher's key onto both kinds of outcome; only the parsed arm ever
// wrote it to the row, so both landed as `unmatched` with a NULL matcher_key.

function withheldReader(string $username): User
{
    return User::create([
        'username' => $username,
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
}

function withheldEml(string $from, string $messageId, string $amountLine): string
{
    return 'From: '.$from."\r\n"
        ."To: reader@example.test\r\n"
        ."Subject: Receipt\r\n"
        ."Date: Sun, 17 May 2026 09:42:13 +0200\r\n"
        .sprintf("Message-ID: <%s@example.test>\r\n", $messageId)
        ."MIME-Version: 1.0\r\n"
        ."Content-Type: text/plain; charset=UTF-8\r\n"
        ."Content-Transfer-Encoding: 8bit\r\n"
        ."\r\n"
        .implode("\n", [
            'Merchant: Nintendo',
            $amountLine,
            'Transaction ID: PAYPALTXN17052026',
        ]);
}

function withheldRowFor(User $user): stdClass
{
    /** @var stdClass $row */
    $row = DB::table('file_imports')->where('user_id', $user->id)->sole();

    return $row;
}

it('names the matcher that read a receipt and withheld it', function (): void {
    $reader = withheldReader('withheld-by-paypal');

    $outcome = app(RecordReceipt::class)(
        withheldEml('service@paypal.com', 'withheld-unmarked-total', 'Bedrag: 1250'),
        $reader,
        'withheld.eml',
    );

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->unmatchedReason)->toBe('unmarked_total')
        ->and($outcome->matcherKey)->toBe('paypal-receipt');

    $row = withheldRowFor($reader);

    expect($row->status)->toBe(InboxMessageStatus::Unmatched->value)
        ->and($row->matcher_key)->toBe('paypal-receipt');
});

it('leaves the matcher null on a message no registered sender claimed', function (): void {
    $reader = withheldReader('claimed-by-nobody');

    $outcome = app(RecordReceipt::class)(
        withheldEml('billing@nobody.example', 'claimed-by-nobody', 'Bedrag: EUR 12,50'),
        $reader,
        'unclaimed.eml',
    );

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->matcherKey)->toBeNull();

    $row = withheldRowFor($reader);

    expect($row->status)->toBe(InboxMessageStatus::Unmatched->value)
        ->and($row->matcher_key)->toBeNull();
});

it('names the matcher that read a message and skipped it', function (): void {
    $reader = withheldReader('skipped-by-paypal');

    $eml = (string) file_get_contents(__DIR__.'/../fixtures/paypal/login-notification.eml');
    $outcome = app(RecordReceipt::class)($eml, $reader, 'login.eml');

    expect($outcome->kind)->toBe(MatchOutcomeKind::Skipped)
        ->and($outcome->matcherKey)->toBe('paypal-receipt');

    $row = withheldRowFor($reader);

    expect($row->status)->toBe(InboxMessageStatus::Skipped->value)
        ->and($row->matcher_key)->toBe('paypal-receipt');
});
