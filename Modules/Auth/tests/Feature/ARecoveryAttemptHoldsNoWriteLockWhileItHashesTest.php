<?php

declare(strict_types=1);

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Hashing\HashManager;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Auth\Public\Actions\SignupAction;
use Modules\Core\Models\User;
use SensitiveParameter;

// config/database.php sets transaction_mode IMMEDIATE, so the write lock is
// taken at BEGIN and held for the whole transaction. Ten bcrypt-12 hashes is
// 3.5 seconds measured on this hardware, the shells serve one request at a
// time, and this endpoint needs no credential to reach.

// Counts the transaction depth every hash is asked at. Wrapping the real
// hasher rather than faking it: the comparison has to keep working, and what
// is being asserted is only where it happens.
final class DepthRecordingHasher implements Hasher
{
    /** @var list<int> */
    public array $depths = [];

    public function __construct(private readonly Hasher $inner, private readonly DatabaseManager $db) {}

    /** @param array<string, mixed> $options */
    public function make(#[SensitiveParameter] $value, array $options = []): string
    {
        $this->record();

        return $this->inner->make($value, $options);
    }

    /** @param array<string, mixed> $options */
    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $this->record();

        return $this->inner->check($value, $hashedValue, $options);
    }

    /** @param array<string, mixed> $options */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->inner->needsRehash($hashedValue, $options);
    }

    /** @return array<string, mixed> */
    public function info($hashedValue): array
    {
        return $this->inner->info($hashedValue);
    }

    private function record(): void
    {
        $this->depths[] = $this->db->connection()->transactionLevel();
    }
}

/**
 * @return array{user: User, codes: list<string>}
 */
function signUpForLockCheck(string $username = 'lockowner'): array
{
    /** @var SignupAction $signup */
    $signup = app(SignupAction::class);
    $result = $signup($username, 'owner-password-123');

    return ['user' => $result['user'], 'codes' => $result['codesPlain']];
}

// The real hasher, never whatever a previous call left bound: wrapping a
// recorder in a recorder counts every hash twice and reads as the subject
// having doubled its work.
function recordingHasher(): DepthRecordingHasher
{
    /** @var Hasher $real */
    $real = app(HashManager::class)->driver();
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $recording = new DepthRecordingHasher($real, $db);
    app()->instance(Hasher::class, $recording);

    return $recording;
}

// RefreshDatabase holds an open transaction for the whole test, so depth is
// never 0 here. What the rule is about is whether the subject opens one of its
// OWN around the hashing, which is depth above this baseline.
function ambientTransactionDepth(): int
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->transactionLevel();
}

it('opens no transaction of its own while it hashes', function (string $which): void {
    ['codes' => $codes] = signUpForLockCheck();
    $baseline = ambientTransactionDepth();
    $recording = recordingHasher();

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = app(RecoveryCodeAuthenticator::class);
    $authenticator->verify('lockowner', $which === 'valid' ? $codes[0] : 'ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ');

    expect($recording->depths)->not->toBeEmpty('No hash was taken at all, so this asserted nothing.');

    expect(array_unique($recording->depths))->toBe(
        [$baseline],
        'A hash was taken at transaction depth '.implode(',', array_unique($recording->depths))
        .'. With transaction_mode IMMEDIATE the write lock is taken at BEGIN, so every other '
        .'process on the database file waits out 3.5 seconds of bcrypt for a caller who needed '
        .'no credential to start it.',
    );
})->with(['valid' => 'valid', 'wrong' => 'wrong']);

it('still costs the same number of hashes whatever the account state', function (): void {
    // The constant-time property the move must not have disturbed: an unknown
    // username pays for ten hashes exactly as a known one does.
    signUpForLockCheck();
    $known = recordingHasher();
    app(RecoveryCodeAuthenticator::class)->verify('lockowner', 'ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ');

    $unknown = recordingHasher();
    app(RecoveryCodeAuthenticator::class)->verify('nobody-here', 'ZZZZ-ZZZZ-ZZZZ-ZZZZ-ZZZZ');

    expect(count($known->depths))->toBe(10)
        ->and(count($unknown->depths))->toBe(10);
});

it('spends a code once when the same one is redeemed twice over', function (): void {
    // The read now happens before the transaction, so spending has to be a
    // compare-and-set rather than a read-then-write. Sequential here because
    // SQLite serialises writers anyway; what is asserted is that the second
    // attempt finds the row already stamped and refuses.
    ['user' => $user, 'codes' => $codes] = signUpForLockCheck();

    /** @var RecoveryCodeAuthenticator $authenticator */
    $authenticator = app(RecoveryCodeAuthenticator::class);

    expect($authenticator->verify('lockowner', $codes[0]))->not->toBeNull()
        ->and($authenticator->verify('lockowner', $codes[0]))->toBeNull();

    expect(UserRecoveryCode::query()->where('user_id', $user->id)->whereNotNull('used_at')->count())
        ->toBe(1, 'One code was offered twice and more than one was spent.');
});
