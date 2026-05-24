<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * Thin DI seam over `config('app.dev_mode')`.
 *
 * Exists so the triple-gate (D-20 / D-21 / D-22) can validate the
 * first lock ("Dev Mode is ON") through a contract that tests can
 * mock without poking `config()` directly. Mirrors PATTERNS.md
 * "Pattern A — DI-only" intent.
 *
 * The default Laravel value of `config('app.dev_mode')` is unset
 * (null); operators flip it on by setting `APP_DEV_MODE=true` in
 * `.env` per CONTEXT D-20.
 */
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
