<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Auth\Public\Contracts\KeyCustodian;

/**
 * The default {@see KeyCustodian} on web / CI and any build without a
 * platform-specific custody binding.
 *
 * The handle IS the raw key: `store`/`read` are the identity function and
 * `forget` does nothing. This preserves the pre-existing behaviour where the
 * raw data key sits in the session payload while unlocked (the documented
 * WR-07 accepted risk for the local-only deployment) — introducing the
 * custody seam changes nothing until a desktop or mobile build overrides
 * this binding.
 */
final class NullKeyCustodian implements KeyCustodian
{
    public function store(string $rawKey): string
    {
        return $rawKey;
    }

    public function read(string $handle): string
    {
        return $handle;
    }

    public function forget(string $handle): void
    {
        // No external state to release — the handle is the key itself.
    }
}
