<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\EmailScan\Internal\OAuth\OAuthStateRepository;

/**
 * @return array{repo: OAuthStateRepository, clock: object}
 */
function makeStateRepoBundle(): array
{
    $session = new Store('test-session', new ArraySessionHandler(120));
    $session->start();

    $clock = new class implements Clock
    {
        public CarbonImmutable $current;

        public function __construct()
        {
            $this->current = CarbonImmutable::create(2026, 5, 17, 12, 0, 0);
        }

        public function now(): CarbonImmutable
        {
            return $this->current;
        }
    };

    return ['repo' => new OAuthStateRepository(SessionFactory::forSession($session), $clock), 'clock' => $clock];
}

function makeStateRepo(): OAuthStateRepository
{
    return makeStateRepoBundle()['repo'];
}

it('issueState followed by consumeState with the same value and user id returns the stored inbox id', function (): void {
    $repo = makeStateRepo();
    $state = $repo->issueState('gmail', userId: 1, existingInboxId: 42);
    expect($state)->toMatch('/^[0-9a-f]{64}$/');

    $inboxId = $repo->consumeState('gmail', $state, currentUserId: 1);
    expect($inboxId)->toBe(42);
});

it('issueState with no existing inbox id returns 0 sentinel from consume', function (): void {
    $repo = makeStateRepo();
    $state = $repo->issueState('gmail', userId: 1);

    $inboxId = $repo->consumeState('gmail', $state, currentUserId: 1);
    expect($inboxId)->toBe(0);
});

it('consumeState with a different value returns null', function (): void {
    $repo = makeStateRepo();
    $repo->issueState('gmail', userId: 1, existingInboxId: 7);

    $result = $repo->consumeState('gmail', bin2hex(random_bytes(32)), currentUserId: 1);
    expect($result)->toBeNull();
});

it('state is single-use — a second consume call returns null even with the correct value', function (): void {
    $repo = makeStateRepo();
    $state = $repo->issueState('gmail', userId: 1, existingInboxId: 9);

    expect($repo->consumeState('gmail', $state, currentUserId: 1))->toBe(9);
    expect($repo->consumeState('gmail', $state, currentUserId: 1))->toBeNull();
});

it('issueState with invalid provider throws InvalidArgumentException', function (): void {
    $repo = makeStateRepo();

    expect(fn () => $repo->issueState('icloud', userId: 1))
        ->toThrow(InvalidArgumentException::class);
});

it('consumeState with invalid provider throws InvalidArgumentException', function (): void {
    $repo = makeStateRepo();

    expect(fn () => $repo->consumeState('icloud', 'whatever', currentUserId: 1))
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
    $state = $repo->issueState('gmail', userId: 1);
    $almost = substr($state, 0, -1).(($state[-1] === '0') ? '1' : '0');

    expect($repo->consumeState('gmail', $almost, currentUserId: 1))->toBeNull();
});

it('different providers maintain independent state slots', function (): void {
    $repo = makeStateRepo();
    $gmailState = $repo->issueState('gmail', userId: 1, existingInboxId: 1);
    $msState = $repo->issueState('microsoft', userId: 1, existingInboxId: 2);

    expect($repo->consumeState('gmail', $msState, currentUserId: 1))->toBeNull();
    expect($repo->consumeState('microsoft', $gmailState, currentUserId: 1))->toBeNull();

    $gmailRepoFresh = $repo; // same instance — the bad consumes already cleared both slots
    // Both slots have been consumed by the failed match attempts;
    // verify subsequent good lookups also return null (single-use).
    expect($gmailRepoFresh->consumeState('gmail', $gmailState, currentUserId: 1))->toBeNull();
    expect($gmailRepoFresh->consumeState('microsoft', $msState, currentUserId: 1))->toBeNull();
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

it('consumeState rejects a state issued under a different user_id (cross-user attach defence)', function (): void {
    $repo = makeStateRepo();
    $state = $repo->issueState('gmail', userId: 1, existingInboxId: 42);

    // User 2 attempts to land the callback that User 1 initiated.
    // The state value is correct (single browser, shared session
    // window) but the user_id binding refuses the consume.
    expect($repo->consumeState('gmail', $state, currentUserId: 2))->toBeNull();
});

it('consumeState rejects a state older than the configured max age', function (): void {
    $bundle = makeStateRepoBundle();
    /** @var OAuthStateRepository $repo */
    $repo = $bundle['repo'];
    /** @var object{current: CarbonImmutable} $clock */
    $clock = $bundle['clock'];

    $state = $repo->issueState('gmail', userId: 1, existingInboxId: 5);

    // Advance the clock past the 10-minute issue window.
    $clock->current = $clock->current->addMinutes(11);

    expect($repo->consumeState('gmail', $state, currentUserId: 1))->toBeNull();
});
