<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Config\Repository;

// The one place SYNC_PORT and SYNC_RELAY_PORT resolve. Every listener,
// spawner and dialler has to agree on the answer, and while each held its own
// copy of the number the documented env vars moved nothing.
final readonly class SyncPorts
{
    public const DEFAULT_PORT = 51337;

    public const DEFAULT_RELAY_PORT = 51338;

    public function __construct(private Repository $config) {}

    public function lan(): int
    {
        return $this->read('sync.port', self::DEFAULT_PORT);
    }

    public function relay(): int
    {
        return $this->read('sync.relay_port', self::DEFAULT_RELAY_PORT);
    }

    // Out-of-range values are returned as configured, not clamped: each caller
    // range-checks and names the port it rejected, and quietly substituting the
    // default is how an env var that reads as honoured changes nothing.
    private function read(string $key, int $default): int
    {
        $value = $this->config->get($key);

        return is_int($value) ? $value : $default;
    }
}
