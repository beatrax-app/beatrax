<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Services;

use Illuminate\Contracts\Config\Repository;

// A DI seam over config('app.dev_mode') — the triple-gate's first lock, so
// tests need to flip it without reaching into config(). Set via APP_DEV_MODE.
final readonly class DevModeFlag
{
    public function __construct(
        private Repository $config,
    ) {}

    public function isOn(): bool
    {
        return $this->config->get('app.dev_mode') === true;
    }
}
