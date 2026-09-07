<?php

declare(strict_types=1);

namespace Modules\Desktop\Tests\Support;

use Modules\Desktop\Internal\Boot\ReadsAMacBundle;

// A bundle described rather than built. codesign and the file magic are the
// two things a Linux runner does not have, and they are the only two things
// the real reader does — so the rules and both commands are exercised through
// this, and the reader itself is judged by the recording it produced.
final class DescribedMacBundle implements ReadsAMacBundle
{
    /**
     * @param  array<string, array<string, mixed>>  $entitlements  path => entitlements
     * @param  array<string, list<string>>  $executables  bundle => relative paths
     * @param  list<string>  $unsigned
     * @param  array<string, array{from_the_store: bool, electron: bool}>  $installed
     */
    public function __construct(
        private readonly array $entitlements = [],
        private readonly array $executables = [],
        private readonly array $unsigned = [],
        private readonly array $installed = [],
    ) {}

    public function entitlementsOf(string $path): array
    {
        return $this->entitlements[$path] ?? [];
    }

    public function isSigned(string $path): bool
    {
        return ! in_array($path, $this->unsigned, true);
    }

    public function executablesIn(string $bundlePath): array
    {
        return $this->executables[$bundlePath] ?? [];
    }

    public function isInAMacOsDirectory(string $relativePath): bool
    {
        return str_contains('/'.$relativePath, '/Contents/MacOS/');
    }

    public function installedBundles(): array
    {
        return $this->installed;
    }
}
