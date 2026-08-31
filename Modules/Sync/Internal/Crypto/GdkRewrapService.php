<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Modules\Sync\Public\Services\PortableKeyMaterial;

final readonly class GdkRewrapService implements GdkRewrapContract
{
    public function __construct(
        private GdkKeyringService $keyringService,
    ) {}

    public function rewrap(int $userId, string $oldKek, string $newKek): void
    {
        if (! file_exists($this->keyringPath($userId))) {
            // No keyring yet for this user (encryption not enabled) — clean
            // no-op; never delegate to rewrapUnderNewKek() here, it would
            // otherwise write a fresh empty keyring file where none existed.
            sodium_memzero($oldKek);
            sodium_memzero($newKek);

            return;
        }

        $this->keyringService->rewrapUnderNewKek($userId, $oldKek, $newKek);
    }

    private function keyringPath(int $userId): string
    {
        return (new PortableKeyMaterial)->keyringPath($userId);
    }
}
