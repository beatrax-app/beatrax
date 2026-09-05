<?php

declare(strict_types=1);

namespace Modules\Mobile\Tests\Support;

use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Psr\Log\NullLogger;

final class PlatformStubVault extends BiometricKeyVault
{
    public function __construct(private readonly string $family, private readonly bool $runtime = true)
    {
        parent::__construct(app(BiometricKeyBlobCodec::class), new NullLogger);
    }

    protected function runtimeAvailable(): bool
    {
        return $this->runtime;
    }

    protected function platformFamily(): string
    {
        return $this->family;
    }
}
