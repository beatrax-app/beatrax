<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Modules\Core\Public\Services\UserDataPathService;
use Native\Mobile\Facades\Network;
use RuntimeException;

/**
 * D-09/D-10 mobile network policy: sync on ANY network by default (D-09,
 * including cellular), unless the user has explicitly enabled "Pause sync
 * on cellular" (D-10, default OFF). Mirrors `RelayConfig`'s file-backed
 * read/write shape (15-PATTERNS.md §NetworkPolicyResolver) — never `.env`.
 *
 * Wraps `nativephp/mobile-network`'s `Network::status()` behind ONE
 * class_exists()-guarded call so `LanSyncClient`/`MobileSyncTriggerService`
 * consult a single policy object rather than touching the native facade
 * directly — mirrors how `RelayClient` consults `RelayConfig`, never reads
 * `relay.json` itself. `nativephp/mobile-network` is present under
 * `mobile-app/vendor/` (the on-device Composer root) but NOT under the
 * repo-root `vendor/` the host test/CI toolchain runs against — the
 * class_exists() guard is load-bearing, not defensive boilerplate.
 */
final class NetworkPolicyResolver
{
    /** Sub-path within appPath() for the D-10 toggle file. */
    private const string CONFIG_SUB = 'mobile/network-policy.json';

    /**
     * Whether a sync tick should proceed right now, per D-09/D-10.
     *
     * D-09: sync on ANY network by default, including cellular.
     * D-10: when "pause on cellular" is enabled AND the current connection
     * is positively confirmed as expensive/cellular, skip.
     *
     * Defaults to "sync now" whenever the current connection type cannot be
     * positively confirmed (native plugin unavailable, status() returns
     * null, or the field is absent) — the pause gate only fires on a
     * positive cellular/expensive signal, never on ambiguity.
     */
    public function shouldSyncNow(): bool
    {
        if (! $this->pauseOnCellular()) {
            return true;
        }

        return ! $this->isCurrentConnectionExpensive();
    }

    /**
     * Whether the D-10 "Pause sync on cellular" toggle is enabled.
     *
     * Absent file / absent key = OFF (D-10 default: sync everywhere).
     */
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
     * Persist the D-10 toggle to `mobile/network-policy.json`. Never
     * `.env` — mirrors `RelayConfig::setEndpointUrl()`'s file-backed write
     * shape (mkdir 0700 → write with LOCK_EX).
     *
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

    /**
     * Reads the current connection status via `nativephp/mobile-network`,
     * class_exists()-guarded so tests / plain web / desktop contexts never
     * fatal (RESEARCH.md's cross-cutting native-facade discipline).
     */
    private function isCurrentConnectionExpensive(): bool
    {
        if (! class_exists(Network::class)) {
            return false;
        }

        $status = Network::status();
        // `Native\Mobile\Facades\Network` is not installed in this
        // toolchain's Composer root (only mobile-app/vendor has it — see
        // the class_exists() guard above), so its return type cannot be
        // resolved statically here; narrow the resulting `mixed` via
        // is_object() rather than a bare null check.
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
