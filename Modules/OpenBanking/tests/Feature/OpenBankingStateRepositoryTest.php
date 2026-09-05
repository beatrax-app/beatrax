<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Session;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SessionFactory;
use Modules\OpenBanking\Internal\OAuth\OpenBankingStateRepository;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

// Written into the session directly rather than through issueState(), because
// half of these shapes describe what the reader must refuse, not what it emits.

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

// The institution is what the callback finishes a consent for, so consuming a
// state has to hand back the one it was issued with, not merely say "yes".
it('hands back the institution the state was issued for', function (): void {
    $repository = obsrRepository();
    $state = $repository->issueState(42, OpenBankingSecretsFixture::INSTITUTION_ID);

    expect($repository->consumeState($state, 42))->toBe(OpenBankingSecretsFixture::INSTITUTION_ID);
});

// Two banks in flight would otherwise finish as one: the second issue replaces
// the first, and whichever callback lands must name its own institution.
it('carries a second bank\'s institution rather than the first one\'s', function (): void {
    $repository = obsrRepository();
    $repository->issueState(42, OpenBankingSecretsFixture::INSTITUTION_ID);
    $second = $repository->issueState(42, OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);

    expect($repository->consumeState($second, 42))->toBe(OpenBankingSecretsFixture::SECOND_INSTITUTION_ID);
});

// pull() is what makes the state single-use, so a replay finds nothing.
it('refuses the same state a second time', function (): void {
    $repository = obsrRepository();
    $state = $repository->issueState(42, OpenBankingSecretsFixture::INSTITUTION_ID);

    expect($repository->consumeState($state, 42))->toBe(OpenBankingSecretsFixture::INSTITUTION_ID)
        ->and($repository->consumeState($state, 42))->toBeNull();
});

it('refuses a callback when no state was ever issued', function (): void {
    expect(obsrRepository()->consumeState('anything', 42))->toBeNull();
});

it('refuses an entry whose stored state is missing or unusable', function (mixed $storedState): void {
    Session::put(OBSR_KEY, [
        'state' => $storedState,
        'user_id' => 42,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'issued_at' => '2026-07-19 06:29:00',
    ]);

    expect(obsrRepository()->consumeState('candidate', 42))->toBeNull();
})->with([
    'absent' => [null],
    'empty' => [''],
    'not a string' => [12345],
]);

it('refuses a state that does not match the one issued', function (): void {
    Session::put(OBSR_KEY, [
        'state' => str_repeat('a', 64),
        'user_id' => 42,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'issued_at' => '2026-07-19 06:29:00',
    ]);

    expect(obsrRepository()->consumeState(str_repeat('b', 64), 42))->toBeNull();
});

// Without this binding, a callback completed while signed in as somebody else
// would attach the bank connection to that other account.
it('refuses a state issued to a different user', function (mixed $storedUserId): void {
    Session::put(OBSR_KEY, [
        'state' => str_repeat('a', 64),
        'user_id' => $storedUserId,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'issued_at' => '2026-07-19 06:29:00',
    ]);

    expect(obsrRepository()->consumeState(str_repeat('a', 64), 42))->toBeNull();
})->with([
    'another user' => [43],
    'not an integer' => ['42'],
    'absent' => [null],
]);

// An entry that names no bank cannot say which consent the callback is
// finishing, and guessing one would attach the session to whichever
// institution happened to be linked first.
it('refuses an entry that names no institution', function (mixed $storedInstitutionId): void {
    Session::put(OBSR_KEY, [
        'state' => str_repeat('a', 64),
        'user_id' => 42,
        'institution_id' => $storedInstitutionId,
        'issued_at' => '2026-07-19 06:29:00',
    ]);

    expect(obsrRepository()->consumeState(str_repeat('a', 64), 42))->toBeNull();
})->with([
    'absent' => [null],
    'empty' => [''],
    'not a string' => [12345],
]);

it('refuses an entry with no usable issue time', function (mixed $issuedAt): void {
    Session::put(OBSR_KEY, [
        'state' => str_repeat('a', 64),
        'user_id' => 42,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'issued_at' => $issuedAt,
    ]);

    expect(obsrRepository()->consumeState(str_repeat('a', 64), 42))->toBeNull();
})->with([
    'absent' => [null],
    'empty' => [''],
    'not a string' => [1_753_000_000],
    'not a date' => ['not-a-timestamp'],
]);

// A negative age is refused too: it means the entry claims to have been issued
// after the clock reads now, which no honest issueState() can produce.
it('refuses a state outside its ten-minute window', function (string $issuedAt, ?string $institutionId): void {
    Session::put(OBSR_KEY, [
        'state' => str_repeat('a', 64),
        'user_id' => 42,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'issued_at' => $issuedAt,
    ]);

    expect(obsrRepository()->consumeState(str_repeat('a', 64), 42))->toBe($institutionId);
})->with([
    'just issued' => ['2026-07-19 06:30:00', OpenBankingSecretsFixture::INSTITUTION_ID],
    'nine minutes old' => ['2026-07-19 06:21:00', OpenBankingSecretsFixture::INSTITUTION_ID],
    'exactly ten minutes old' => ['2026-07-19 06:20:00', OpenBankingSecretsFixture::INSTITUTION_ID],
    'a second past the window' => ['2026-07-19 06:19:59', null],
    'issued in the future' => ['2026-07-19 06:31:00', null],
]);
