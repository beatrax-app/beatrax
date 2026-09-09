<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// Answers for the native capability probe. The vault used to decide this from
// PHP_OS_FAMILY, so a stub only had to name an operating system; it now asks
// the device, and a stub has to say what the device said back.
final class PlatformStubVault extends BiometricKeyVault
{
    /**
     * @param  array{available?: bool, reason?: string}  $capability
     */
    public function __construct(
        private readonly array $capability,
        private readonly bool $runtime = true,
        ?LoggerInterface $log = null,
    ) {
        parent::__construct(app(BiometricKeyBlobCodec::class), $log ?? new NullLogger);
    }

    protected function runtimeAvailable(): bool
    {
        return $this->runtime;
    }

    /**
     * @return array{available?: bool, reason?: string}
     */
    protected function vaultCapability(): array
    {
        return $this->capability;
    }
}
