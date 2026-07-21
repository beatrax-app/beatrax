<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Native\Desktop\Facades\System;

// NOT YET WIRED: no caller exists. The lock screen currently offers
// only the WebAuthn (browser) biometric path. The crypto release logic
// lives entirely in the Auth module; this class only ever returns a
// bool — native Touch ID needs a Desktop→Auth bridge still to be built.
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
