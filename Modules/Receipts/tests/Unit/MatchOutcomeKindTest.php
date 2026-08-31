<?php

declare(strict_types=1);

use Modules\Core\Public\Enums\InboxMessageStatus;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Receipts\Public\Dto\MatcherInputDto;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;

// The enum's own comment already said its values ARE the stored statuses, and
// three call sites hand-rolled the map anyway: two if/elseif chains and a
// ternary, each free to drift from the sentence documenting them.

it('answers the stored status for every outcome a matcher can give', function (): void {
    $map = [];
    foreach (MatchOutcomeKind::cases() as $kind) {
        $map[$kind->value] = $kind->toInboxStatus();
    }

    expect($map)->toBe([
        'parsed' => InboxMessageStatus::Parsed,
        'skipped' => InboxMessageStatus::Skipped,
        'unmatched' => InboxMessageStatus::Unmatched,
    ]);
});

// Fetched is the state a message is in BEFORE matching, so it is not reachable
// from an outcome — which is exactly why the map is total over the three cases.
it('never answers Fetched, the state that precedes matching', function (): void {
    foreach (MatchOutcomeKind::cases() as $kind) {
        expect($kind->toInboxStatus())->not->toBe(InboxMessageStatus::Fetched);
    }
});

it('hands the matcher a message stamped with the Fetched status, not a bare literal', function (): void {
    $input = new MatcherInputDto(
        id: 7,
        userId: 3,
        source: 'file-drop',
        providerMessageId: 'mid-mok-1',
        senderEmail: 'service@paypal.com',
        senderName: null,
        subject: 'Receipt',
        internalDate: new DateTimeImmutable('2026-05-17T09:30:00+00:00'),
        emlPath: '/tmp/dummy.eml',
    );

    $dto = $input->toInboxMessageDto();

    expect($dto)->toBeInstanceOf(InboxMessageDto::class);
    expect($dto->status)->toBe(InboxMessageStatus::Fetched->value);
});
