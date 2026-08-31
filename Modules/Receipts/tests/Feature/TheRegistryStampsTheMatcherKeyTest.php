<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Enums\InboxMessageStatus;
use Modules\EmailScan\Public\Dto\InboxMessageDto;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Dto\MatcherInputDto;
use Modules\Receipts\Public\Dto\MatchOutcomeDto;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;

// The key that reached inbox_messages.matcher_key used to be a literal each
// matcher wrote a SECOND time into an untyped raw_payload, which two callers
// then is_string()-guessed back out of a mixed. The registry knows which
// matcher answered; SenderMatcher::key() had no production consumer at all.

beforeEach(function (): void {
    $seeded = $this->seedFixtureUserAndAccount();
    $this->fixtureUser = $seeded['user'];
});

it('stamps the answering matcher own key onto the outcome, not the raw payload', function (): void {
    /** @var MatcherRegistry $registry */
    $registry = $this->app->make(MatcherRegistry::class);
    $raw = (string) file_get_contents(__DIR__.'/../fixtures/paypal/current-receipt.eml');

    /** @var RecordReceipt $record */
    $record = $this->app->make(RecordReceipt::class);
    $outcome = $record($raw, $this->fixtureUser, 'paypal.eml');

    expect($outcome->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($outcome->matcherKey)->toBe('paypal-receipt');
    expect($outcome->matcherKey)->toBeIn($registry->supportedKeys());
    expect($outcome->parsed?->rawPayload)->not->toHaveKey('matcher_key');

    $row = DB::table('file_imports')->where('user_id', $this->fixtureUser->id)->first();
    expect($row->matcher_key)->toBe('paypal-receipt');
    expect($row->status)->toBe(InboxMessageStatus::Parsed->value);
});

// Every registered matcher must be reachable by its own key, or a stamped
// value could never be traced back to the matcher that produced it.
it('publishes a key for every matcher the registry dispatches over', function (): void {
    /** @var MatcherRegistry $registry */
    $registry = $this->app->make(MatcherRegistry::class);

    $keys = $registry->supportedKeys();

    expect($keys)->toContain('paypal-receipt', 'ics-receipt', 'google-play-receipt');
    expect($keys)->toBe(array_values(array_unique($keys)));
});

it('stamps the key even on an outcome the matcher declined to parse', function (): void {
    $declining = new class implements SenderMatcher
    {
        public function key(): string
        {
            return 'declining-matcher';
        }

        public function priority(): int
        {
            return 100;
        }

        public function canHandle(InboxMessageDto $msg): bool
        {
            return true;
        }

        public function match(string $emlRaw): MatchOutcomeDto
        {
            return MatchOutcomeDto::skipped('declined');
        }
    };

    $registry = new MatcherRegistry([$declining]);
    $outcome = $registry->dispatch(
        new MatcherInputDto(
            id: 1,
            userId: $this->fixtureUser->id,
            source: 'inbox',
            providerMessageId: 'mid-decline',
            senderEmail: 'anyone@example.test',
            senderName: null,
            subject: 'anything',
            internalDate: new DateTimeImmutable('2026-05-17T09:30:00+00:00'),
            emlPath: '/tmp/dummy.eml',
        ),
        '',
    );

    expect($outcome->kind)->toBe(MatchOutcomeKind::Skipped);
    expect($outcome->matcherKey)->toBe('declining-matcher');
});

it('leaves the key null when no matcher claimed the message', function (): void {
    $registry = new MatcherRegistry([]);
    $outcome = $registry->dispatch(
        new MatcherInputDto(
            id: 1,
            userId: $this->fixtureUser->id,
            source: 'inbox',
            providerMessageId: 'mid-unclaimed',
            senderEmail: 'nobody@example.test',
            senderName: null,
            subject: 'anything',
            internalDate: new DateTimeImmutable('2026-05-17T09:30:00+00:00'),
            emlPath: '/tmp/dummy.eml',
        ),
        '',
    );

    expect($outcome->kind)->toBe(MatchOutcomeKind::Unmatched);
    expect($outcome->matcherKey)->toBeNull();
});
