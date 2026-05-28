<?php

declare(strict_types=1);

namespace Modules\Auth\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;

/**
 * Materialises five `user_recovery_codes` rows for the primary demo
 * user so the recovery-codes surface renders both the active-codes
 * view and the consumption audit:
 *
 *   - 3 unused codes (`used_at IS NULL`)
 *   - 2 used codes (`used_at` stamped at different ages)
 *
 * Code hashes are derived from `hash('sha256', 'demo-' . $seedKey)`
 * so the same rows produce the same hashes on every re-run; the
 * UNIQUE index on `code_hash` therefore makes a second seed run a
 * no-op via `updateOrCreate` keyed on the hash.
 *
 * The literal plaintext code never lands on disk (that would defeat
 * the purpose of the hashed column); the contributor inspecting the
 * recovery-codes surface sees the consumption state, not the codes.
 */
final class DemoRecoveryCodesSeeder
{
    /**
     * Per-code seed. `usedAgeHours` of `null` keeps the row unused;
     * any integer stamps `used_at` at that many hours before now.
     *
     * @var list<array{seedKey: string, usedAgeHours: ?int}>
     */
    private const CODES = [
        ['seedKey' => 'recovery-1', 'usedAgeHours' => null],
        ['seedKey' => 'recovery-2', 'usedAgeHours' => null],
        ['seedKey' => 'recovery-3', 'usedAgeHours' => null],
        ['seedKey' => 'recovery-4', 'usedAgeHours' => 24],
        ['seedKey' => 'recovery-5', 'usedAgeHours' => 240],
    ];

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            foreach (self::CODES as $row) {
                $this->upsertCode($primary, $row);
            }
        }

        return UserRecoveryCode::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{seedKey: string, usedAgeHours: ?int}  $row
     */
    private function upsertCode(User $user, array $row): void
    {
        $hash = hash('sha256', 'demo-'.$row['seedKey']);
        $usedAt = $row['usedAgeHours'] === null
            ? null
            : CarbonImmutable::now()->subHours($row['usedAgeHours']);

        UserRecoveryCode::query()->updateOrCreate(
            ['code_hash' => $hash],
            [
                'user_id' => $user->id,
                'used_at' => $usedAt,
            ],
        );
    }
}
