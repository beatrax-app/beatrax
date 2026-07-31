<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final readonly class MobileProvisioningCredentials
{
    // Carries the plaintext PIN and account password only for the duration
    // of a single local-provisioning attempt. MUST NEVER be persisted,
    // logged, or rendered into a Livewire snapshot — the import component
    // clears the source properties the moment it hands them over.
    public function __construct(
        public int $userId,
        public string $pin,
        public string $accountPassword,
    ) {}
}
