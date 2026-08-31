<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * @link ../../../../.docs/features/dev-mode/the-console-on-a-shipped-build.md
 */
final readonly class DevConsoleBuildGate
{
    // The development environments, named. Testing "is not production" instead
    // let a self-hosted staging, a hand-written prod and a capitalised
    // Production all read as a checkout and open the console with no key.
    private const array DEVELOPMENT = ['local', 'testing'];

    public function __construct(
        private Repository $config,
    ) {}

    public function permits(): bool
    {
        if ($this->isDevelopmentBuild()) {
            return true;
        }

        // A phone has no launcher to pass a flag to, and every mobile bundle
        // is built with BEATRAX_DEV_MODE cleaned out of its .env — so the one
        // thing still chosen per install is which build was put on the device.
        return UserDataPathService::isMobileRuntime()
            ? $this->config->get('app.debug') === true
            : $this->config->get('app.dev_mode') === true;
    }

    private function isDevelopmentBuild(): bool
    {
        $environment = $this->config->get('app.env');

        return is_string($environment)
            && in_array(strtolower(trim($environment)), self::DEVELOPMENT, true);
    }
}
