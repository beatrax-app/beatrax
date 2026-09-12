<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;

// `users.base_currency` is a reporting preference — what roll-ups render in —
// and it was reaching the PayPal parse as the denomination of a figure the
// message itself marked with nothing. The currency does not label the figure,
// it scales it: at the euro's hundredth "Bedrag: 1250" is 12.50 and at the
// yen's unit it is 1250, so one message was two amounts a hundred apart
// depending on a picker in /settings.

function readerReportingIn(string $username, string $code): User
{
    return User::create([
        'username' => $username,
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => $code,
    ]);
}

function paypalEmlWithBody(string $messageId, string $amountLine): string
{
    return "From: service@paypal.com\r\n"
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

it('reads one message the same way for two readers who report in different money', function (): void {
    $eml = paypalEmlWithBody('unmarked-total', 'Bedrag: 1250');

    $forEuroReader = app(RecordReceipt::class)($eml, readerReportingIn('reports-in-euro', Currency::Eur->value), 'unmarked.eml');
    $forYenReader = app(RecordReceipt::class)($eml, readerReportingIn('reports-in-yen', Currency::Jpy->value), 'unmarked.eml');

    expect($forYenReader->kind)->toBe($forEuroReader->kind)
        ->and($forYenReader->parsed?->currency)->toBe($forEuroReader->parsed?->currency)
        ->and($forYenReader->parsed?->amountMinor)->toBe($forEuroReader->parsed?->amountMinor);
});

it('reads one message the same way after the reader changes what roll-ups render in', function (): void {
    $reader = readerReportingIn('changes-the-picker', Currency::Eur->value);
    $eml = paypalEmlWithBody('unmarked-total-again', 'Bedrag: 1250');

    $before = app(RecordReceipt::class)($eml, $reader, 'unmarked.eml');

    $reader->base_currency = Currency::Jpy->value;
    $reader->save();

    $after = app(RecordReceipt::class)($eml, $reader->fresh(), 'unmarked.eml');

    expect($after->kind)->toBe($before->kind)
        ->and($after->parsed?->currency)->toBe($before->parsed?->currency)
        ->and($after->parsed?->amountMinor)->toBe($before->parsed?->amountMinor);
});

it('records a total the message denominated with nothing as a miss', function (): void {
    $outcome = app(RecordReceipt::class)(
        paypalEmlWithBody('no-mark-at-all', 'Bedrag: 1250'),
        readerReportingIn('reader-of-the-miss', Currency::Eur->value),
        'unmarked.eml',
    );

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched)
        ->and($outcome->unmatchedReason)->toBe('unmarked_total')
        ->and($outcome->parsed)->toBeNull();
});

it('still reads a total the message did denominate, whatever the reader reports in', function (): void {
    $eml = paypalEmlWithBody('marked-total', 'Bedrag: EUR 12,50');

    $forEuroReader = app(RecordReceipt::class)($eml, readerReportingIn('euro-reader-of-a-mark', Currency::Eur->value), 'marked.eml');
    $forYenReader = app(RecordReceipt::class)($eml, readerReportingIn('yen-reader-of-a-mark', Currency::Jpy->value), 'marked.eml');

    expect($forEuroReader->kind)->toBe(MatchOutcomeKind::Parsed)
        ->and($forEuroReader->parsed?->currency)->toBe(Currency::Eur->value)
        ->and($forEuroReader->parsed?->amountMinor)->toBe(-1250)
        ->and($forYenReader->parsed?->currency)->toBe(Currency::Eur->value)
        ->and($forYenReader->parsed?->amountMinor)->toBe(-1250);
});
