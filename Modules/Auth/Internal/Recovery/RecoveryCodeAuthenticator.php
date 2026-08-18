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

    // A fixed number of bcrypt comparisons per attempt — the provisioned
    // recovery-code count (RegenerateRecoveryCodesCommand issues 10). Both the
    // account-not-found and the wrong-code paths run exactly this many hashes,
    // so response time never distinguishes a missing username from a wrong code.
    private const HASH_OPS = 10;

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
            $connection = $this->db->connection();
            $username = strtolower(trim($usernameInput));

            $user = User::query()->where('username', $username)->first();
            $candidate = $this->reformat($this->normalizer->normalize($codeInput));

            // The unused code rows for a real account, or none for an unknown
            // username. Loaded under a row lock so a matched code is consumed
            // atomically within this transaction.
            $unused = $user instanceof User
                ? $connection->table('user_recovery_codes')
                    ->where('user_id', $user->id)
                    ->whereNull('used_at')
                    ->lockForUpdate()
                    ->get(['id', 'code_hash'])
                    ->all()
                : [];

            // Run a FIXED number of bcrypt comparisons regardless of whether the
            // account exists or how many codes remain, so neither response time
            // nor match position distinguishes a missing username from a wrong
            // code. Padding uses make() (same bcrypt cost as check()), one per pass.
            $matchedId = null;
            $iterations = max(self::HASH_OPS, count($unused));
            for ($i = 0; $i < $iterations; $i++) {
                $row = $unused[$i] ?? null;
                if ($row !== null && is_string($row->code_hash)) {
                    if ($this->hasher->check($candidate, $row->code_hash) && $matchedId === null) {
                        $matchedId = $row->id;
                    }
                } else {
                    $this->hasher->make($candidate);
                }
            }

            if ($user instanceof User && $matchedId !== null) {
                $connection->table('user_recovery_codes')
                    ->where('id', $matchedId)
                    ->update(['used_at' => $this->clock->now()]);
                $this->emitSuccess($user);

                return $user;
            }

            // A failure against a real account warns its owner. An unknown
            // username has no owner to warn, and a user_id=null alert is shown
            // to every user (SystemAlertQuery), so recording one would let an
            // unauthenticated caller flood every banner — so it is not recorded.
            if ($user instanceof User) {
                $this->emitFailure($user);
            }

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

    private function emitFailure(User $user): void
    {
        SystemAlert::query()->create([
            'user_id' => $user->id,
            'kind' => 'auth.recovery_code_failed',
            'severity' => 'critical',
            'message' => "Failed recovery code attempt for {$user->username}.",
        ]);
    }
}
