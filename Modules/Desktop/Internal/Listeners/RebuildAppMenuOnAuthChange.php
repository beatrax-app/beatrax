<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Desktop\Internal\Native\AppMenuBuilder;

// The menu is built once per launch, before any session exists, so the
// developer submenu it gates on the signed-in user could never appear. Signing
// in and out are the two moments that answer differently.
final readonly class RebuildAppMenuOnAuthChange
{
    public function __construct(
        private AppMenuBuilder $appMenu,
        private ConfigRepository $config,
    ) {}

    public function handle(): void
    {
        // Outside the Electron bundle there is no menu to replace, and the
        // create call would POST to an endpoint nothing is serving.
        if ($this->config->get('nativephp-internal.running') !== true) {
            return;
        }

        $this->appMenu->install();
    }
}
