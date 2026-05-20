<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Shared verification core for account recovery by code.
 *
 * `verify()` takes a typed username and recovery code, finds the user,
 * and matches the code against that user's unused `user_recovery_codes`
 * rows. The lookup, the per-row hash comparison, and the `used_at` stamp
 * all run inside one transaction with the candidate rows held under
 * `lockForUpdate()`: a concurrent redemption of the same code blocks on
 * the row lock rather than racing, so a code can be consumed exactly
 * once.
 *
 * The recovery codes are bcrypt-hashed at issue time as the formatted
 * five-group hyphenated string (`AAAA-BBBB-CCCC-DDDD-EEEE`). The typed
 * input may arrive lowercase or with stray separators, so it is first
 * normalised to the bare twenty characters and then re-formatted into
 * that identical hyphenated shape before `Hasher::check`.
 *
 * Every attempt — a match or any failure — writes a `system_alerts`
 * audit row: `auth.recovery_code_consumed` (severity `warning`) on
 * success, `auth.recovery_code_failed` (severity `critical`) on failure.
 * A failure against an unknown username carries a null `user_id`.
 *
 * On a match the matched `User` is returned; on any failure `verify()`
 * returns null and never throws — the caller decides how to surface the
 * generic "username and code do not match" message without revealing
 * whether the username existed.
 */
final class RecoveryCodeAuthenticator
{
    private const GROUP_LENGTH = 4;

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

    /**
     * Re-inserts a hyphen every four characters so the normalised bare
     * code is compared in the same shape it was hashed in.
     */
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
