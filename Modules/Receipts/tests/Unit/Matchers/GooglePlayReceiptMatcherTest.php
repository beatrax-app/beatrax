<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Internal\Matchers\GooglePlayReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Public\Dto\MatcherInputDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

function googlePlayMatcher(): GooglePlayReceiptMatcher
{
    return new GooglePlayReceiptMatcher(new EmlMimeReader, new ReceiptBodyText);
}

function googlePlayInbox(string $senderEmail, ?string $subject = null): InboxMessageDto
{
    return new InboxMessageDto(
        id: 1,
        userId: 1,
        inboxId: 1,
        providerMessageId: 'gp-mid-1',
        internalDate: new DateTimeImmutable('2026-05-17T09:30:00+00:00'),
        senderEmail: $senderEmail,
        senderName: 'Google Play',
        subject: $subject,
        status: 'fetched',
        fetchedAt: new DateTimeImmutable('2026-05-17T09:30:00+00:00'),
    );
}

it('claims googleplay-noreply@google.com via exact equality', function (): void {
    $matcher = googlePlayMatcher();
    expect($matcher->canHandle(googlePlayInbox('googleplay-noreply@google.com')))->toBeTrue();
});

it('rejects look-alike google.com.attacker.example senders (spoofing defence)', function (): void {
    $matcher = googlePlayMatcher();
    expect($matcher->canHandle(googlePlayInbox('googleplay-noreply@google.com.attacker.example')))->toBeFalse();
});

it('rejects bare noreply@google.com — exact full address required', function (): void {
    $matcher = googlePlayMatcher();
    expect($matcher->canHandle(googlePlayInbox('noreply@google.com')))->toBeFalse();
});

it('rejects unrelated sender domains', function (): void {
    $matcher = googlePlayMatcher();
    expect($matcher->canHandle(googlePlayInbox('notifications@netflix.com')))->toBeFalse();
});

it('rejects an empty sender email', function (): void {
    $matcher = googlePlayMatcher();
    expect($matcher->canHandle(googlePlayInbox('')))->toBeFalse();
});

it('publishes the stable matcher key and priority', function (): void {
    $matcher = googlePlayMatcher();
    expect($matcher->key())->toBe('google-play-receipt');
    expect($matcher->priority())->toBe(100);
});

it('parses a current-generation Google Play receipt with the strict GPA order-id format', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/googleplay/current-receipt.eml');
    $matcher = googlePlayMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->parsed)->not->toBeNull();
    $dto = $outcome->parsed;
    expect($dto)->toBeInstanceOf(ParsedReceiptDto::class);
    expect($dto->referenceId)->toBe('GPA.1234-5678-9012-34567');
    expect(PatternScan::matches('/^GPA\.[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{5}$/', (string) $dto?->referenceId))->toBeTrue();
    expect($dto?->amountMinor)->toBe(-1299);
    expect($dto?->currency)->toBe('USD');
    expect($dto?->ownIban)->toBe('GOOGLE-PLAY');
    expect($dto?->bookedAt->toDateString())->toBe('2026-05-17');
    expect($dto?->bookedAt->format('H:i:s'))->toBe('00:00:00');
});

it('preserves both native USD and settled EUR legs for a foreign-currency receipt', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/googleplay/foreign-currency-receipt.eml');
    $matcher = googlePlayMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    $dto = $outcome->parsed;
    expect($dto?->amountMinor)->toBe(-1299);
    expect($dto?->currency)->toBe('USD');
    expect($dto?->settledAmountMinor)->toBe(-1207);
    expect($dto?->settledCurrency)->toBe('EUR');
});

it('mirrors native USD into settled when no EUR conversion leg is present', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/googleplay/current-receipt.eml');
    $matcher = googlePlayMatcher();

    $outcome = $matcher->match($raw);

    $dto = $outcome->parsed;
    expect($dto)->not->toBeNull();
    expect($dto?->settledAmountMinor)->toBe($dto?->amountMinor);
    expect($dto?->settledCurrency)->toBe($dto?->currency);
});

it('returns skipped(googleplay-refund-v2) for refund-subject receipts (v2 deferred)', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/googleplay/refund-receipt.eml');
    $matcher = googlePlayMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Skipped);
    expect($outcome->skipReason)->toBe('googleplay-refund-v2');
});

it('returns unmatched when the body lacks a GPA order id', function (): void {
    $raw = <<<'EML'
        From: googleplay-noreply@google.com
        To: user@example.test
        Subject: Your Google Play Order
        Date: Sun, 17 May 2026 09:30:00 +0000
        MIME-Version: 1.0
        Content-Type: text/plain; charset=UTF-8

        Hello — your order was processed. No order number visible.
        EML;
    $matcher = googlePlayMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
});

it('routes a Google Play sender via MatcherRegistry to GooglePlayReceiptMatcher', function (): void {
    $registry = new MatcherRegistry([googlePlayMatcher()]);
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/googleplay/current-receipt.eml');
    $input = new MatcherInputDto(
        id: 1,
        userId: 1,
        source: 'inbox',
        providerMessageId: 'gp-mid-1',
        senderEmail: 'googleplay-noreply@google.com',
        senderName: 'Google Play',
        subject: 'Your Google Play Order Receipt',
        internalDate: new DateTimeImmutable('2026-05-17T09:30:00+00:00'),
        emlPath: '/tmp/dummy.eml',
    );

    $outcome = $registry->dispatch($input, $raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->parsed?->referenceId)->toBe('GPA.1234-5678-9012-34567');
    expect($outcome->matcherKey)->toBe('google-play-receipt');
});
