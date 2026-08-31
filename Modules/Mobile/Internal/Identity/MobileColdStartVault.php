<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Identity;

use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\ColdStartEnrolmentFlag;

// Presents the enclave-gated key vault through the shared ColdStartVault
// contract, so the lock screen asks one question on every platform instead
// of branching on which native stack happens to be installed.
/**
 * @link ../../../../.docs/design/cold-start-biometric-unlock.md
 */
final readonly class MobileColdStartVault implements ColdStartVault
{
    public function __construct(
        private BiometricKeyVault $vault,
        private ColdStartEnrolmentFlag $enrolment,
    ) {}

    public function isAvailable(): bool
    {
        return $this->vault->isAvailable();
    }

    // Reads the stored flag rather than the enclave: touching the entry
    // itself would fire the biometric prompt just to render a button.
    public function isEnrolled(int $userId): bool
    {
        return $this->enrolment->isEnrolled($userId);
    }

    public function enroll(int $userId, string $dataKey): bool
    {
        $enrolled = $this->vault->enroll($userId, $dataKey);

        $this->enrolment->mark($userId, $enrolled);

        return $enrolled;
    }

    // The enclave entry IS the biometric gate, so recover() prompts on its
    // own — never fire a second prompt around it.
    public function recover(int $userId, string $reason): ?string
    {
        $result = $this->vault->recover($userId, $reason);

        return $result->isRecovered() ? $result->dataKey : null;
    }

    public function forget(int $userId): void
    {
        $this->vault->clear($userId);
        $this->enrolment->mark($userId, false);
    }
}
