<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Native\Desktop\Facades\System;

final class NativeBiometricUnlock
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function isAvailable(): bool
    {
        if ($this->config->get('nativephp-internal.running') !== true) {
            return false;
        }

        return System::canPromptTouchID();
    }

    public function prompt(string $reason = 'Unlock beatrax'): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        return System::promptTouchID($reason);
    }
}
