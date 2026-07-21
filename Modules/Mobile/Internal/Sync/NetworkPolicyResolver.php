<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Modules\Core\Public\Services\UserDataPathService;
use Native\Mobile\Facades\Network;
use RuntimeException;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class NetworkPolicyResolver
{
    private const string CONFIG_SUB = 'mobile/network-policy.json';

    // Sync on any network by default, including cellular, unless the
    // pause-on-cellular toggle is enabled AND the current connection is
    // positively confirmed as expensive/cellular. Defaults to "sync now"
    // whenever the connection type cannot be positively confirmed.
    public function shouldSyncNow(): bool
    {
        if (! $this->pauseOnCellular()) {
            return true;
        }

        return ! $this->isCurrentConnectionExpensive();
    }

    public function pauseOnCellular(): bool
    {
        $path = UserDataPathService::appPath(self::CONFIG_SUB);

        if (! file_exists($path)) {
            return false;
        }

        $json = file_get_contents($path);
        if ($json === false || $json === '') {
            return false;
        }

        $data = json_decode($json, true, 512, 0);
        if (! is_array($data)) {
            return false;
        }

        return ($data['pause_on_cellular'] ?? false) === true;
    }

    /**
     * @throws RuntimeException on an I/O failure.
     */
    public function setPauseOnCellular(bool $enabled): void
    {
        $path = UserDataPathService::appPath(self::CONFIG_SUB);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            if (! mkdir($dir, 0700, true)) {
                throw new RuntimeException("Cannot create network-policy directory: {$dir}");
            }
        }

        $data = ['pause_on_cellular' => $enabled];
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write network policy to: {$path}");
        }
    }

    // class_exists()-guarded so tests/plain web/desktop contexts never
    // fatal.
    private function isCurrentConnectionExpensive(): bool
    {
        if (! class_exists(Network::class)) {
            return false;
        }

        $status = Network::status();
        // The facade is not installed in this toolchain's Composer root,
        // so its return type cannot be resolved statically here; narrow
        // the resulting `mixed` via is_object() rather than a bare null check.
        if (! is_object($status)) {
            return false;
        }

        // No declared shape — read it via get_object_vars() rather than
        // dynamic property access on an untyped object.
        $fields = get_object_vars($status);

        $isExpensive = ($fields['isExpensive'] ?? null) === true;
        $type = $fields['type'] ?? null;

        return $isExpensive || $type === 'cellular';
    }
}
