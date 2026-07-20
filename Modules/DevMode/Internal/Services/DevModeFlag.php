<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Services;

use Illuminate\Contracts\Config\Repository;

// Thin DI seam over config('app.dev_mode') so the triple-gate's first
// lock ("Dev Mode is ON") can be validated through a contract tests can
// mock without poking config() directly. Default is unset (null);
// operators flip it on via APP_DEV_MODE=true in .env.
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
