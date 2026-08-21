<?php

declare(strict_types=1);

use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Public\Dto\ChainHintPayload\FundedByCardPayload;
use Modules\Receipts\Public\Dto\MatcherInputDto;
use Modules\Receipts\Public\Dto\ParsedReceiptDto;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;

function icsMatcher(): IcsReceiptMatcher
{
    return new IcsReceiptMatcher(new EmlMimeReader);
}

function icsInbox(string $senderEmail, ?string $subject = null): InboxMessageDto
{
    return new InboxMessageDto(
        id: 1,
        userId: 1,
        inboxId: 1,
        providerMessageId: 'ics-mid-1',
        internalDate: new DateTimeImmutable('2026-04-12T10:15:00+02:00'),
        senderEmail: $senderEmail,
        senderName: 'ICS',
        subject: $subject,
        status: 'fetched',
        fetchedAt: new DateTimeImmutable('2026-04-12T10:15:00+02:00'),
    );
}

it('claims noreply@ics.nl senders via exact domain match', function (): void {
    $matcher = icsMatcher();
    expect($matcher->canHandle(icsInbox('noreply@ics.nl')))->toBeTrue();
});

it('claims transacties@icscards.nl senders via exact domain match', function (): void {
    $matcher = icsMatcher();
    expect($matcher->canHandle(icsInbox('transacties@icscards.nl')))->toBeTrue();
});

it('rejects look-alike ics.nl.attacker.example domains (spoofing defence)', function (): void {
    $matcher = icsMatcher();
    expect($matcher->canHandle(icsInbox('noreply@ics.nl.attacker.example')))->toBeFalse();
});

it('rejects unrelated sender domains', function (): void {
    $matcher = icsMatcher();
    expect($matcher->canHandle(icsInbox('notifications@netflix.com')))->toBeFalse();
});

it('rejects an empty sender email', function (): void {
    $matcher = icsMatcher();
    expect($matcher->canHandle(icsInbox('')))->toBeFalse();
});

it('publishes the stable matcher key and priority', function (): void {
    $matcher = icsMatcher();
    expect($matcher->key())->toBe('ics-receipt');
    expect($matcher->priority())->toBe(100);
});

it('parses a current-generation ICS receipt into a ParsedReceiptDto with a FundedByCard chain hint', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/ics/current-receipt.eml');
    $matcher = icsMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe('parsed');
    expect($outcome->parsed)->not->toBeNull();
    $dto = $outcome->parsed;
    expect($dto)->toBeInstanceOf(ParsedReceiptDto::class);
    expect($dto->merchantName)->toBe('SYNTHETIC ICS TINY');
    expect($dto->amountMinor)->toBe(-100);
    expect($dto->currency)->toBe('EUR');
    expect($dto->referenceId)->toBe('XYZ123');
    expect($dto->ownIban)->toBe('ICS-CARD');
    expect($dto->bookedAt->toDateString())->toBe('2026-04-12');
    expect($dto->bookedAt->format('H:i:s'))->toBe('00:00:00');
    expect($dto->rawPayload['matcher_key'] ?? null)->toBe('ics-receipt');

    // Chain-hint payload — exposes the extracted card last-four so the
    // RecordReceipt action can dispatch ChainHintDetected post-persistence.
    expect($dto->chainHints)->toHaveCount(1);
    $hint = $dto->chainHints[0];
    expect($hint)->toBeInstanceOf(FundedByCardPayload::class);
    /** @var FundedByCardPayload $hint */
    expect($hint->cardLast4)->toBe('1234');
});

it('falls back to prior-generation anchors (kaart **** 1234, Merchant:, Autorisatiecode:)', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/ics/prior-generation-receipt.eml');
    $matcher = icsMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe('parsed');
    expect($outcome->parsed)->not->toBeNull();
    $dto = $outcome->parsed;
    expect($dto?->merchantName)->toBe('GELDMAAT ROELANTDREEF 239');
    expect($dto?->amountMinor)->toBe(-5000);
    expect($dto?->currency)->toBe('EUR');
    expect($dto?->referenceId)->toBe('A9B8C7');
    expect($dto?->chainHints)->toHaveCount(1);
    /** @var FundedByCardPayload $hint */
    $hint = $dto?->chainHints[0];
    expect($hint->cardLast4)->toBe('1234');
});

it('returns skipped(pdf_attachment_v2_only) for a PDF-attachment statement email with no inline amount', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/ics/pdf-attachment-receipt.eml');
    $matcher = icsMatcher();

    $outcome = $matcher->match($raw);

    expect($outcome->kind)->toBe('skipped');
    expect($outcome->skipReason)->toBe('pdf_attachment_v2_only');
});

it('mirrors native into settled for EUR-only receipts', function (): void {
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/ics/current-receipt.eml');
    $matcher = icsMatcher();

    $outcome = $matcher->match($raw);

    $dto = $outcome->parsed;
    expect($dto)->not->toBeNull();
    expect($dto?->settledAmountMinor)->toBe($dto?->amountMinor);
    expect($dto?->settledCurrency)->toBe($dto?->currency);
});

it('routes an ICS sender via MatcherRegistry to IcsReceiptMatcher', function (): void {
    $registry = new MatcherRegistry([icsMatcher()]);
    $raw = (string) file_get_contents(__DIR__.'/../../fixtures/ics/current-receipt.eml');
    $input = new MatcherInputDto(
        id: 1,
        userId: 1,
        source: 'inbox',
        providerMessageId: 'mid-1',
        senderEmail: 'noreply@ics.nl',
        senderName: 'ICS',
        subject: 'Aankoopnotificatie',
        internalDate: new DateTimeImmutable('2026-04-12T10:15:00+02:00'),
        emlPath: '/tmp/dummy.eml',
    );

    $outcome = $registry->dispatch($input, $raw);

    expect($outcome->kind)->toBe('parsed');
    expect($outcome->parsed?->merchantName)->toBe('SYNTHETIC ICS TINY');
});
