<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Native;

use Native\Desktop\Client\Client;
use Throwable;

// safeStorage.isEncryptionAvailable() answers true on Linux for a backend that
// derives its key from a password published in Chromium's own source, so the
// one signal NativePHP exposes cannot tell a keyring from a stand-in for one.
/**
 * @link ../../../../.docs/features/desktop/architecture.md#what-safestorage-is-worth-on-linux
 */
final class SafeStorageBackendProbe
{
    // The route the prebuild hook injects, spelled here and in
    // scripts/nativephp_inject_safe_storage_backend.php; SafeStorageBackendProbeTest
    // holds the two spellings together.
    public const string BACKEND_ROUTE = 'storage-backend';

    // Electron's own words for a Linux safeStorage that no keyring is behind:
    // basic_text is the hardcoded-password fallback, and unknown means
    // Chromium could not name the backend it settled on.
    private const array UNPROTECTING_BACKENDS = ['basic_text', 'unknown'];

    private ?bool $protects = null;

    public function __construct(
        private readonly Client $client,
        private readonly string $platformFamily = PHP_OS_FAMILY,
    ) {}

    public function protects(): bool
    {
        return $this->protects ??= $this->measure();
    }

    private function measure(): bool
    {
        // macOS and Windows each have exactly one safeStorage backend --
        // Keychain Services and DPAPI -- so there is no second-rate mode for
        // isEncryptionAvailable() to have been true about.
        if ($this->platformFamily !== 'Linux') {
            return true;
        }

        $backend = $this->backend();

        return $backend !== null && ! in_array($backend, self::UNPROTECTING_BACKENDS, true);
    }

    // Null where the route is absent -- a bundle built before the hook existed,
    // or one whose hook left its red line in the build log -- and where the
    // shell refused. An answer nobody gave is not evidence of a keyring.
    private function backend(): ?string
    {
        try {
            $result = $this->client->get('system/'.self::BACKEND_ROUTE)->json('result');
        } catch (Throwable) {
            return null;
        }

        return is_string($result) && $result !== '' ? $result : null;
    }
}
