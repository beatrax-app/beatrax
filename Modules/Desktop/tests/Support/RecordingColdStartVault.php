<?php

declare(strict_types=1);

namespace Modules\Desktop\Tests\Support;

use Modules\Auth\Public\Contracts\ColdStartVault;

final class RecordingColdStartVault implements ColdStartVault
{
    /** @var list<int> */
    public array $forgotten = [];

    public function isAvailable(): bool
    {
        return true;
    }

    public function isEnrolled(int $userId): bool
    {
        return true;
    }

    public function enroll(int $userId, string $dataKey): bool
    {
        return true;
    }

    public function recover(int $userId, string $reason): ?string
    {
        return null;
    }

    public function forget(int $userId): void
    {
        $this->forgotten[] = $userId;
    }
}
