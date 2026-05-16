<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;

function makeStateRepo(): OAuthStateRepository
{
    $session = new Store('test-session', new ArraySessionHandler(120));
    $session->start();

    $clock = new class implements Clock
    {
        public function now(): CarbonImmutable
        {
            return CarbonImmutable::create(2026, 5, 17, 12, 0, 0);
        }
    };

    return new OAuthStateRepository($session, $clock);
}

it('issueState followed by consumeState with the same value returns the stored inbox id', function (): void {
    $repo = makeStateRepo();
    $state = $repo->issueState('gmail', existingInboxId: 42);
    expect($state)->toMatch('/^[0-9a-f]{64}$/');

    $inboxId = $repo->consumeState('gmail', $state);
    expect($inboxId)->toBe(42);
});

it('issueState with no existing inbox id returns 0 sentinel from consume', function (): void {
    $repo = makeStateRepo();
    $state = $repo->issueState('gmail');

    $inboxId = $repo->consumeState('gmail', $state);
    expect($inboxId)->toBe(0);
});

it('consumeState with a different value returns null', function (): void {
    $repo = makeStateRepo();
    $repo->issueState('gmail', existingInboxId: 7);

    $result = $repo->consumeState('gmail', bin2hex(random_bytes(32)));
    expect($result)->toBeNull();
});

it('state is single-use — a second consume call returns null even with the correct value', function (): void {
    $repo = makeStateRepo();
    $state = $repo->issueState('gmail', existingInboxId: 9);

    expect($repo->consumeState('gmail', $state))->toBe(9);
    expect($repo->consumeState('gmail', $state))->toBeNull();
});

it('issueState with invalid provider throws InvalidArgumentException', function (): void {
    $repo = makeStateRepo();

    expect(fn () => $repo->issueState('icloud'))
        ->toThrow(InvalidArgumentException::class);
});

it('consumeState with invalid provider throws InvalidArgumentException', function (): void {
    $repo = makeStateRepo();

    expect(fn () => $repo->consumeState('icloud', 'whatever'))
        ->toThrow(InvalidArgumentException::class);
});

it('hash_equals — a near-match on the first byte still returns null', function (): void {
    // Deliberately craft a candidate that differs only in the last
    // byte. A naive `===` would short-circuit on the first
    // difference; hash_equals compares every byte regardless. We
    // cannot test the timing directly but we CAN confirm the
    // comparison is correctness-preserving even for prefix-similar
    // candidates.
    $repo = makeStateRepo();
    $state = $repo->issueState('gmail');
    $almost = substr($state, 0, -1).(($state[-1] === '0') ? '1' : '0');

    expect($repo->consumeState('gmail', $almost))->toBeNull();
});

it('different providers maintain independent state slots', function (): void {
    $repo = makeStateRepo();
    $gmailState = $repo->issueState('gmail', existingInboxId: 1);
    $msState = $repo->issueState('microsoft', existingInboxId: 2);

    expect($repo->consumeState('gmail', $msState))->toBeNull();
    expect($repo->consumeState('microsoft', $gmailState))->toBeNull();

    $gmailRepoFresh = $repo; // same instance — the bad consumes already cleared both slots
    // Both slots have been consumed by the failed match attempts;
    // verify subsequent good lookups also return null (single-use).
    expect($gmailRepoFresh->consumeState('gmail', $gmailState))->toBeNull();
    expect($gmailRepoFresh->consumeState('microsoft', $msState))->toBeNull();
});

it('issueClientWizardSuccess stores a timestamp without throwing', function (): void {
    $repo = makeStateRepo();

    $repo->issueClientWizardSuccess('gmail');

    expect(true)->toBeTrue(); // pure side-effect; the verification is "does not throw"
});

it('issueClientWizardSuccess rejects unknown providers', function (): void {
    $repo = makeStateRepo();

    expect(fn () => $repo->issueClientWizardSuccess('icloud'))
        ->toThrow(InvalidArgumentException::class);
});
