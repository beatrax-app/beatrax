<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Public\Dto\MatcherInputDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;

function paypalMatcher(): PaypalReceiptMatcher
{
    return new PaypalReceiptMatcher(new EmlMimeReader, app(BaseCurrency::class));
}

function paypalInbox(string $senderEmail, ?string $subject = null): InboxMessageDto
{
    return new InboxMessageDto(
        id: 1,
        userId: 1,
        inboxId: 1,
        providerMessageId: 'mid-1',
        internalDate: new DateTimeImmutable('2026-05-17T09:42:13+02:00'),
        senderEmail: $senderEmail,
        senderName: 'PayPal',
        subject: $subject,
        status: 'fetched',
        fetchedAt: new DateTimeImmutable('2026-05-17T09:42:13+02:00'),
    );
}

it('claims @paypal.com senders via exact domain match', function (): void {
    $matcher = paypalMatcher();
    expect($matcher->canHandle(paypalInbox('service@paypal.com')))->toBeTrue();
});

it('rejects look-alike paypal.com.attacker.example domains (spoofing defence)', function (): void {
    $matcher = paypalMatcher();
    expect($matcher->canHandle(paypalInbox('service@paypal.com.attacker.example')))->toBeFalse();
});

it('rejects unrelated sender domains', function (): void {
    $matcher = paypalMatcher();
    expect($matcher->canHandle(paypalInbox('notifications@netflix.com')))->toBeFalse();
});

it('rejects an empty sender email', function (): void {
    $matcher = paypalMatcher();
    expect($matcher->canHandle(paypalInbox('')))->toBeFalse();
});

it('publishes the stable matcher key and priority', function (): void {
    $matcher = paypalMatcher();
    expect($matcher->key())->toBe('paypal-receipt');
    expect($matcher->priority())->toBe(100);
});

it('parses a current-generation Dutch PayPal receipt into a ParsedReceiptDto', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/paypal/current-receipt.eml');
    $matcher = paypalMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->parsed)->not->toBeNull();
    $dto = $outcome->parsed;
    expect($dto)->toBeInstanceOf(ParsedReceiptDto::class);
    expect($dto->merchantName)->toBe('Netflix BV');
    expect($dto->amountMinor)->toBe(-1299);
    expect($dto->currency)->toBe('EUR');
    expect($dto->referenceId)->toBe('PAYPALTXN17052026');
    expect(preg_match('/^[A-Z0-9]{17}$/', (string) $dto->referenceId))->toBe(1);
    expect($dto->ownIban)->toBe('PAYPAL');
    expect($dto->bookedAt->toDateString())->toBe('2026-05-17');
    // bookedAt MUST be normalised to startOfDay so receipt + CSV
    // fingerprints align (FingerprintComposer v3 day-precision contract).
    expect($dto->bookedAt->format('H:i:s'))->toBe('00:00:00');
    expect($dto->rawPayload['matcher_key'] ?? null)->toBe('paypal-receipt');
});

it('falls back to English merchant/amount anchors on a prior-generation PayPal receipt', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/paypal/prior-generation-receipt.eml');
    $matcher = paypalMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->parsed?->merchantName)->toBe('Netflix BV');
    expect($outcome->parsed?->amountMinor)->toBe(-1299);
    expect($outcome->parsed?->currency)->toBe('EUR');
});

it('returns skipped(paypal-login-notification) for a sign-in notice', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/paypal/login-notification.eml');
    $matcher = paypalMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Skipped);
    expect($outcome->skipReason)->toBe('paypal-login-notification');
});

it('extracts both native and settled legs for foreign-currency receipts', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/paypal/foreign-currency-receipt.eml');
    $matcher = paypalMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    $dto = $outcome->parsed;
    expect($dto?->amountMinor)->toBe(-1299);
    expect($dto?->currency)->toBe('USD');
    expect($dto?->settledAmountMinor)->toBe(-1182);
    expect($dto?->settledCurrency)->toBe('EUR');
});

it('mirrors native into settled when the EUR-only receipt has no second-currency leg', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/paypal/current-receipt.eml');
    $matcher = paypalMatcher();

    $outcome = $matcher->match($raw);

    $dto = $outcome->parsed;
    expect($dto)->not->toBeNull();
    expect($dto?->settledAmountMinor)->toBe($dto?->amountMinor);
    expect($dto?->settledCurrency)->toBe($dto?->currency);
});

it('returns unmatched(invalid_date_header) without throwing when the Date header is unparseable', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/paypal/malformed-date-receipt.eml');
    $matcher = paypalMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
    expect($outcome->unmatchedReason)->toBe('invalid_date_header');
});

it('routes a PayPal sender via MatcherRegistry to PaypalReceiptMatcher', function (): void {
    $registry = new MatcherRegistry([paypalMatcher()]);
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/paypal/current-receipt.eml');
    $input = new MatcherInputDto(
        id: 1,
        userId: 1,
        source: 'inbox',
        providerMessageId: 'mid-1',
        senderEmail: 'service@paypal.com',
        senderName: 'PayPal',
        subject: 'Je ontvangstbewijs van Netflix BV',
        internalDate: new DateTimeImmutable('2026-05-17T09:42:13+02:00'),
        emlPath: '/tmp/dummy.eml',
    );

    $outcome = $registry->dispatch($input, $raw);

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->parsed?->merchantName)->toBe('Netflix BV');
});

it('returns unmatched when no matcher claims the sender', function (): void {
    $registry = new MatcherRegistry([paypalMatcher()]);
    $input = new MatcherInputDto(
        id: 1,
        userId: 1,
        source: 'inbox',
        providerMessageId: 'mid-other',
        senderEmail: 'notifications@netflix.com',
        senderName: 'Netflix',
        subject: 'Your account has been charged',
        internalDate: new DateTimeImmutable('2026-05-17T09:00:00+02:00'),
        emlPath: '/tmp/dummy.eml',
    );

    $outcome = $registry->dispatch($input, '');

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
    expect($outcome->parsed)->toBeNull();
});

it('ReceiptSourceAdapter::toSourceDto maps every field per the interfaces table', function (): void {
    $adapter = new ReceiptSourceAdapter;
    $bookedAt = CarbonImmutable::parse('2026-05-17T00:00:00+00:00');

    $parsed = new ParsedReceiptDto(
        merchantName: 'Netflix BV',
        amountMinor: -1299,
        currency: 'EUR',
        settledAmountMinor: -1299,
        settledCurrency: 'EUR',
        referenceId: 'PAYPALTXN17052026',
        bookedAt: $bookedAt,
        ownIban: 'PAYPAL',
        description: 'Vooraf goedgekeurde betaling / Netflix BV',
        rawPayload: ['matcher_key' => 'paypal-receipt'],
    );

    $source = $adapter->toSourceDto($parsed, sourceRowIndex: 0);

    expect($source->bookedAt->toDateTimeString())->toBe($bookedAt->toDateTimeString());
    expect($source->postedAt->toDateTimeString())->toBe($bookedAt->toDateTimeString());
    expect($source->valueDate->toDateTimeString())->toBe($bookedAt->toDateTimeString());
    expect($source->ownIban)->toBe('PAYPAL');
    expect($source->counterpartyIban)->toBeNull();
    expect($source->counterpartyName)->toBe('Netflix BV');
    expect($source->currency)->toBe('EUR');
    expect($source->amountMinor)->toBe(-1299);
    expect($source->sourceRef)->toBe('PAYPALTXN17052026');
    expect($source->settledAmountMinor)->toBe(-1299);
    expect($source->settledCurrency)->toBe('EUR');
    expect($source->fxRateUsed)->toBeNull();
    expect($source->sourceRowIndex)->toBe(0);
});
