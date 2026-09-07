<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Boot;

// Everything the App Store review needs to know about a bundle that can only
// be learned from the machine: what codesign says, what the file magic says,
// and which bundles are installed. Behind a seam so the rules can be exercised
// against a described bundle rather than only against one somebody built.
interface ReadsAMacBundle
{
    /** @return array<string, mixed> */
    public function entitlementsOf(string $path): array;

    public function isSigned(string $path): bool;

    /** @return list<string> paths relative to the bundle */
    public function executablesIn(string $bundlePath): array;

    public function isInAMacOsDirectory(string $relativePath): bool;

    // `electron` matters as much as `from_the_store`: the negative control has
    // to be an app shaped like this one, or it disproves rules this product
    // will never meet.
    /** @return array<string, array{from_the_store: bool, electron: bool}> */
    public function installedBundles(): array;
}
