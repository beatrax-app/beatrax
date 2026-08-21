<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemAlertWriter;
use stdClass;

final class RecoveryCodeAuthenticator
{
    private const GROUP_LENGTH = 4;

    // A fixed bcrypt count per attempt, matching the ten codes issued, so
    // response time never separates a missing username from a wrong code.
    private const HASH_OPS = 10;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Hasher $hasher,
        private readonly Clock $clock,
        private readonly RecoveryCodeNormalizer $normalizer,
        private readonly SystemAlertWriter $alerts,
    ) {}

    public function verify(string $usernameInput, string $codeInput): ?User
    {
        /** @var list<array{userId: int, kind: string, severity: string, message: string}> $alerts */
        $alerts = [];

        /** @var User|null $result */
        $result = $this->db->connection()->transaction(function () use ($usernameInput, $codeInput, &$alerts): ?User {
            $connection = $this->db->connection();
            $username = strtolower(trim($usernameInput));

            /** @var User|null $user */
            $user = User::query()->where('username', $username)->first();
            $candidate = $this->reformat($this->normalizer->normalize($codeInput));

            $matchedId = $this->matchingCodeId($candidate, $this->unusedCodes($connection, $user));

            if ($user instanceof User && $matchedId !== null) {
                $connection->table('user_recovery_codes')
                    ->where('id', $matchedId)
                    ->update(['used_at' => $this->clock->now()]);
                $alerts[] = self::successAlert($user);

                return $user;
            }

            // An unknown username has no owner to warn, and a user_id=null
            // alert shows to everyone, so recording one would let an
            // unauthenticated caller flood every banner.
            if ($user instanceof User) {
                $alerts[] = self::failureAlert($user);
            }

            return null;
        });

        // After commit: the alert is the owner's own row and has to reach
        // their other device, which only the writer arranges.
        foreach ($alerts as $alert) {
            $this->alerts->raiseForUser($alert['userId'], $alert['kind'], $alert['severity'], $alert['message']);
        }

        return $result;
    }

    // Row-locked, so a matched code is consumed atomically by the caller.
    /** @return array<int, stdClass> */
    private function unusedCodes(Connection $connection, ?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        return $connection->table('user_recovery_codes')
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->lockForUpdate()
            ->get(['id', 'code_hash'])
            ->all();
    }

    // A fixed comparison count whatever the account state, so neither timing
    // nor match position leaks it. The padding uses make(), which costs the
    // same as check().
    /** @param array<int, stdClass> $unused */
    private function matchingCodeId(string $candidate, array $unused): mixed
    {
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

        return $matchedId;
    }

    // The bare code is hashed in hyphenated shape, so restore it before check.
    private function reformat(string $bareCode): string
    {
        $groups = str_split($bareCode, self::GROUP_LENGTH);

        return implode('-', $groups);
    }

    /** @return array{userId: int, kind: string, severity: string, message: string} */
    private static function successAlert(User $user): array
    {
        return [
            'userId' => $user->id,
            'kind' => 'auth.recovery_code_consumed',
            'severity' => 'warning',
            'message' => "Recovery code used by {$user->username}.",
        ];
    }

    /** @return array{userId: int, kind: string, severity: string, message: string} */
    private static function failureAlert(User $user): array
    {
        return [
            'userId' => $user->id,
            'kind' => 'auth.recovery_code_failed',
            'severity' => 'critical',
            'message' => "Failed recovery code attempt for {$user->username}.",
        ];
    }
}
