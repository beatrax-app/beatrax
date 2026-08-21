<?php

declare(strict_types=1);

namespace Modules\Auth\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Modules\Auth\Models\UserRecoveryCode;
use Modules\Core\Models\User;

final class DemoRecoveryCodesSeeder
{
    /**
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
