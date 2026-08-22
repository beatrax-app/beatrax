<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

// The one door out of PHP and into a platform's own discovery API. It is an
// interface so a test can answer for the device: the real one is two global
// functions a C extension defines, which exist only inside a shipped app.
/**
 * @link ../../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
 */
interface NativeBridge
{
    // Whether the running shell registered this bridge function at all. False
    // is the ordinary answer everywhere but a mobile build carrying the plugin
    // that provides it, and it is the difference between a discovery road that
    // exists and one that does not.
    public function supports(string $function): bool;

    /**
     * @param  array<string, scalar>  $parameters
     * @return array<mixed>|null `null` when the call did not reach a native
     *                           implementation or answered nothing readable.
     */
    public function call(string $function, array $parameters): ?array;
}
