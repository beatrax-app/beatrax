<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Native\Desktop\Facades\System;

// Native Touch ID availability and prompt. Deliberately has no caller: the
// prompt yields a bool, while unlocking needs the data key back, so a desktop
// equivalent of the mobile cold-start vault (a wrapped KEK persisted under
// safeStorage) must exist first. The lock screen offers WebAuthn until then.
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
