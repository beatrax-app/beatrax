<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

// Answers for the bridge CALL rather than for the capability, so the reading
// between the two runs for real. PlatformStubVault pins the far side of that
// reading and leaves it unexecuted; this one leaves it in the test's path.
final class BridgeAnswerStubVault extends BiometricKeyVault
{
    public function __construct(
        private readonly mixed $answer,
        ?LoggerInterface $log = null,
    ) {
        parent::__construct(app(BiometricKeyBlobCodec::class), $log ?? new NullLogger);
    }

    protected function runtimeAvailable(): bool
    {
        return true;
    }

    protected function capabilityAnswer(): mixed
    {
        return $this->answer;
    }
}
