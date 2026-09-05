<?php

declare(strict_types=1);

namespace Modules\Auth\Tests\Support;

use Modules\Auth\Public\Contracts\ColdStartVault;

// Stands in for the desktop vault: enrolment is durable material that outlives
// the row, which is the whole reason the stale copy went unnoticed.
final class DurableColdStartVault implements ColdStartVault
{
    /** @var array<int, string> */
    public array $keys = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function isEnrolled(int $userId): bool
    {
        return array_key_exists($userId, $this->keys);
    }

    public function enroll(int $userId, string $dataKey): bool
    {
        $this->keys[$userId] = $dataKey;

        return true;
    }

    public function recover(int $userId, string $reason): ?string
    {
        return $this->keys[$userId] ?? null;
    }

    public function forget(int $userId): void
    {
        unset($this->keys[$userId]);
    }
}
