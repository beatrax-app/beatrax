<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
final class RecoveryCodeAuthenticator
{
    private const GROUP_LENGTH = 4;

    // Verified against on the account-not-found path so a missing username is
    // not visibly faster than a real one's code check — the same enumeration
    // oracle the login path closes. The check always fails; the plaintext is
    // irrelevant.
    private const DUMMY_HASH = '$2y$12$izdfjJdUqNnoGna9BMw/Uu9nmn8X0cCc9YwktU.V/7/mkWX6jvOLS';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly Clock $clock,
        private readonly RecoveryCodeNormalizer $normalizer,
    ) {}

    public function verify(string $usernameInput, string $codeInput): ?User
    {
        /** @var User|null $result */
        $result = $this->db->connection()->transaction(function () use ($usernameInput, $codeInput): ?User {
            $username = strtolower(trim($usernameInput));

            $user = User::query()->where('username', $username)->first();

            if (! $user instanceof User) {
                $this->hasher->check($codeInput, self::DUMMY_HASH);
                $this->emitFailure($usernameInput, null);

                return null;
            }

            $candidate = $this->reformat($this->normalizer->normalize($codeInput));

            $connection = $this->db->connection();

            $unused = $connection->table('user_recovery_codes')
                ->where('user_id', $user->id)
                ->whereNull('used_at')
                ->lockForUpdate()
                ->get(['id', 'code_hash']);

            foreach ($unused as $code) {
                if (! is_string($code->code_hash)) {
                    continue;
                }

                if ($this->hasher->check($candidate, $code->code_hash)) {
                    $connection->table('user_recovery_codes')
                        ->where('id', $code->id)
                        ->update(['used_at' => $this->clock->now()]);
                    $this->emitSuccess($user);

                    return $user;
                }
            }

            $this->emitFailure($usernameInput, $user);

            return null;
        });

        return $result;
    }

    // Re-inserts a hyphen every four characters so the normalised bare
    // code is compared in the same shape it was hashed in.
    private function reformat(string $bareCode): string
    {
        $groups = str_split($bareCode, self::GROUP_LENGTH);

        return implode('-', $groups);
    }

    private function emitSuccess(User $user): void
    {
        SystemAlert::query()->create([
            'user_id' => $user->id,
            'kind' => 'auth.recovery_code_consumed',
            'severity' => 'warning',
            'message' => "Recovery code used by {$user->username}.",
        ]);
    }

    private function emitFailure(string $usernameInput, ?User $user): void
    {
        SystemAlert::query()->create([
            'user_id' => $user?->id,
            'kind' => 'auth.recovery_code_failed',
            'severity' => 'critical',
            'message' => 'Failed recovery code attempt for '.strtolower(trim($usernameInput)).'.',
        ]);
    }
}
