<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Exceptions;

use RuntimeException;

// Raised when a patch script the store submission depends on did not apply. A
// cosmetic patch that fails degrades to the unpatched shell, which is visible
// on the device; these are invisible until App Store review rejects the build,
// so the build stops here instead.
final class NativeBuildPatchException extends RuntimeException
{
    public static function requiredScriptFailed(string $script, string $failure): self
    {
        return new self(
            "NativeBuildPatches: {$script} failed, and the artefact it writes is required for App Store "
            ."submission. Refusing to build without it.\n{$failure}",
        );
    }
}
