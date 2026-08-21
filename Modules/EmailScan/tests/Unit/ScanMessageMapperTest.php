<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\Jobs\ScanMessageMapper;

beforeEach(function (): void {
    $this->frozen = CarbonImmutable::createFromTimestamp(1_700_000_000);
    $this->clock = new class($this->frozen) implements Clock
    {
        public function __construct(private CarbonImmutable $now) {}

        public function now(): CarbonImmutable
        {
            return $this->now;
        }
    };

    $this->mapper = new ScanMessageMapper($this->clock);
});

it('pulls message ids out of Gmail history entries and skips malformed shapes', function (): void {
    $entries = [
        ['messagesAdded' => [
            ['message' => ['id' => 'a1', 'threadId' => 't1']],
            ['message' => ['id' => '', 'threadId' => 't2']],
            ['message' => ['id' => 42]],
            ['message' => 'not-an-array'],
            'not-an-array',
        ]],
        ['messagesAdded' => 'not-an-array'],
        ['labelAdded' => [['message' => ['id' => 'ignored']]]],
        ['messagesAdded' => [['message' => ['id' => 'a2', 'threadId' => 't3']]]],
    ];

    expect($this->mapper->extractGmailHistoryMessageIds($entries))->toBe(['a1', 'a2']);
});

it('matches a sender by domain suffix, by substring, and rejects a miss', function (): void {
    expect($this->mapper->matchesAnyPattern('billing@shop.example', ['@shop.example']))->toBeTrue()
        ->and($this->mapper->matchesAnyPattern('billing@other.example', ['@shop.example']))->toBeFalse()
        ->and($this->mapper->matchesAnyPattern('noreply@bank.example', ['bank']))->toBeTrue()
        ->and($this->mapper->matchesAnyPattern('noreply@bank.example', ['stripe']))->toBeFalse();
});

it('extracts and lowercases the sender address, tolerating missing shapes', function (): void {
    $full = ['from' => ['emailAddress' => ['address' => 'Billing@Shop.Example']]];

    expect($this->mapper->extractSenderAddress($full))->toBe('billing@shop.example')
        ->and($this->mapper->extractSenderAddress(['from' => 'nope']))->toBe('')
        ->and($this->mapper->extractSenderAddress(['from' => ['emailAddress' => 'nope']]))->toBe('')
        ->and($this->mapper->extractSenderAddress(['from' => ['emailAddress' => ['address' => 42]]]))->toBe('');
});

it('extracts the provider message id or an empty string', function (): void {
    expect($this->mapper->extractProviderMessageId(['id' => 'abc']))->toBe('abc')
        ->and($this->mapper->extractProviderMessageId(['id' => 42]))->toBe('')
        ->and($this->mapper->extractProviderMessageId([]))->toBe('');
});

it('reads the provider receivedDateTime as the internal date, or null when absent', function (): void {
    $meta = ['receivedDateTime' => '2026-01-02T03:04:05Z'];

    expect($this->mapper->graphMessageInternalDate($meta)?->format('Y-m-d\TH:i:s\Z'))->toBe('2026-01-02T03:04:05Z')
        ->and($this->mapper->graphMessageInternalDate(['receivedDateTime' => '']))->toBeNull()
        ->and($this->mapper->graphMessageInternalDate([]))->toBeNull();
});

it('parses a date, falling back to the injected clock when the string is unparseable', function (): void {
    expect($this->mapper->safeParseDate('2026-01-02T03:04:05Z')->format('Y-m-d'))->toBe('2026-01-02')
        ->and($this->mapper->safeParseDate('not a date')->getTimestamp())->toBe($this->frozen->getTimestamp());
});
