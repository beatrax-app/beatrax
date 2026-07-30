<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Session;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;

/*
 * Every way consumeState() says no.
 *
 * This is the CSRF binding for the Enable Banking consent redirect: the value
 * it guards is a callback that attaches a bank connection to an account. A
 * rejection that silently became an acceptance would let a redirect crafted
 * elsewhere land a connection on the wrong user, so each refusal is asserted
 * on its own rather than inferred from the happy path failing.
 *
 * The entry is written into the session directly rather than through
 * issueState(), because half of these shapes are ones issueState() cannot
 * produce — which is the point: they describe what the reader must refuse,
 * not what the writer happens to emit.
 */

const OBSR_KEY = 'open_banking_oauth_state';

function obsrRepository(string $now = '2026-07-19 06:30:00'): OpenBankingStateRepository
{
    $clock = new class($now) implements Clock
    {
        public function __construct(private readonly string $now) {}

        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse($this->now);
        }
    };

    return new OpenBankingStateRepository(app(SessionFactory::class), $clock);
}

it('accepts the state it issued for the user it issued it to', function (): void {
    $repository = obsrRepository();
    $state = $repository->issueState(42);

    expect($repository->consumeState($state, 42))->toBeTrue();
});

// Single-use: the pull() is what enforces it, so a replayed callback finds
// nothing left to match against.
it('refuses the same state a second time', function (): void {
    $repository = obsrRepository();
    $state = $repository->issueState(42);

    expect($repository->consumeState($state, 42))->toBeTrue()
        ->and($repository->consumeState($state, 42))->toBeFalse();
});

it('refuses a callback when no state was ever issued', function (): void {
    expect(obsrRepository()->consumeState('anything', 42))->toBeFalse();
});

it('refuses an entry whose stored state is missing or unusable', function (mixed $storedState): void {
    Session::put(OBSR_KEY, [
        'state' => $storedState,
        'user_id' => 42,
        'issued_at' => '2026-07-19 06:29:00',
    ]);

    expect(obsrRepository()->consumeState('candidate', 42))->toBeFalse();
})->with([
    'absent' => [null],
    'empty' => [''],
    'not a string' => [12345],
]);

it('refuses a state that does not match the one issued', function (): void {
    Session::put(OBSR_KEY, [
        'state' => str_repeat('a', 64),
        'user_id' => 42,
        'issued_at' => '2026-07-19 06:29:00',
    ]);

    expect(obsrRepository()->consumeState(str_repeat('b', 64), 42))->toBeFalse();
});

// The consent flow must finish under the account that began it. Without this
// binding, a callback completed while signed in as somebody else would attach
// the bank connection to that other account.
it('refuses a state issued to a different user', function (mixed $storedUserId): void {
    Session::put(OBSR_KEY, [
        'state' => str_repeat('a', 64),
        'user_id' => $storedUserId,
        'issued_at' => '2026-07-19 06:29:00',
    ]);

    expect(obsrRepository()->consumeState(str_repeat('a', 64), 42))->toBeFalse();
})->with([
    'another user' => [43],
    'not an integer' => ['42'],
    'absent' => [null],
]);

it('refuses an entry with no usable issue time', function (mixed $issuedAt): void {
    Session::put(OBSR_KEY, [
        'state' => str_repeat('a', 64),
        'user_id' => 42,
        'issued_at' => $issuedAt,
    ]);

    expect(obsrRepository()->consumeState(str_repeat('a', 64), 42))->toBeFalse();
})->with([
    'absent' => [null],
    'empty' => [''],
    'not a string' => [1_753_000_000],
    'not a date' => ['not-a-timestamp'],
]);

// Ten minutes is the whole window. A negative age is refused too: it means
// the entry claims to have been issued after the clock reads now, which no
// honest issueState() can produce.
it('refuses a state outside its ten-minute window', function (string $issuedAt, bool $accepted): void {
    Session::put(OBSR_KEY, [
        'state' => str_repeat('a', 64),
        'user_id' => 42,
        'issued_at' => $issuedAt,
    ]);

    expect(obsrRepository()->consumeState(str_repeat('a', 64), 42))->toBe($accepted);
})->with([
    'just issued' => ['2026-07-19 06:30:00', true],
    'nine minutes old' => ['2026-07-19 06:21:00', true],
    'exactly ten minutes old' => ['2026-07-19 06:20:00', true],
    'a second past the window' => ['2026-07-19 06:19:59', false],
    'issued in the future' => ['2026-07-19 06:31:00', false],
]);
