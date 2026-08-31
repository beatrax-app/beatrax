<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Recovery;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Support\Username;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;
use stdClass;

final readonly class RecoveryCodeAuthenticator
{
    private const int GROUP_LENGTH = 4;

    // A fixed bcrypt count per attempt, matching the ten codes issued, so
    // response time never separates a missing username from a wrong code.
    // Kept at ten and not lowered — that is the real cost of ten independently
    // salted hashes; ResetPasswordAction caps how often it can be spent.
    private const int HASH_OPS = 10;

    public function __construct(
        private DatabaseManager $db,
        private Hasher $hasher,
        private Clock $clock,
        private RecoveryCodeNormalizer $normalizer,
        private SystemAlertWriter $alerts,
    ) {}

    public function verify(string $usernameInput, string $codeInput): ?User
    {
        /** @var list<array{userId: int, kind: string, severity: string, message: string, metadata: array<string, mixed>, once: bool}> $alerts */
        $alerts = [];

        /** @var User|null $result */
        $result = $this->db->connection()->transaction(function () use ($usernameInput, $codeInput, &$alerts): ?User {
            $connection = $this->db->connection();
            $username = Username::normalize($usernameInput);

            /** @var User|null $user */
            $user = User::query()->where('username', $username)->first();
            $candidate = $this->hyphenate($this->normalizer->normalize($codeInput));

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
            // unauthenticated caller flood every banner. A KNOWN username was
            // the other half of that flood, which is why the row below is one.
            if ($user instanceof User) {
                $alerts[] = self::failureAlert($user);
            }

            return null;
        });

        // After commit: the alert is the owner's own row and has to reach
        // their other device, which only the writer arranges.
        foreach ($alerts as $alert) {
            $alert['once']
                ? $this->alerts->raiseOnceForUser($alert['userId'], $alert['kind'], $alert['severity'], $alert['message'], $alert['metadata'])
                : $this->alerts->raiseForUser($alert['userId'], $alert['kind'], $alert['severity'], $alert['message'], $alert['metadata']);
        }

        return $result;
    }

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

    private function hyphenate(string $bareCode): string
    {
        $groups = str_split($bareCode, self::GROUP_LENGTH);

        return implode('-', $groups);
    }

    // A redemption spends a code, so there are ten of these at most and each
    // one is a distinct thing that happened.
    /** @return array{userId: int, kind: string, severity: string, message: string, metadata: array<string, mixed>, once: bool} */
    private static function successAlert(User $user): array
    {
        $line = CopyLine::of('core::alerts.messages.auth_recovery_code_consumed', ['username' => $user->username]);

        return [
            'userId' => $user->id,
            'kind' => 'auth.recovery_code_consumed',
            'severity' => 'warning',
            'message' => $line->sentence(),
            'metadata' => StoredCopy::inParams($line) + ['username' => $user->username],
            'once' => false,
        ];
    }

    // A failure spends nothing, so nothing caps how many arrive. One open row
    // says everything a hundred would, and a mistyped sheet no longer buries
    // the reader's own banner under critical rows.
    /** @return array{userId: int, kind: string, severity: string, message: string, metadata: array<string, mixed>, once: bool} */
    private static function failureAlert(User $user): array
    {
        $line = CopyLine::of('core::alerts.messages.auth_recovery_code_failed', ['username' => $user->username]);

        return [
            'userId' => $user->id,
            'kind' => 'auth.recovery_code_failed',
            'severity' => 'critical',
            'message' => $line->sentence(),
            'metadata' => StoredCopy::inParams($line) + ['username' => $user->username],
            'once' => true,
        ];
    }
}
