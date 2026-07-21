<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Auth\Public\Contracts\KeyCustodian;

// The default {@see KeyCustodian} on web / CI and any build without a
// platform-specific custody binding. The handle IS the raw key, so
// introducing the custody seam changes nothing until a desktop or mobile
// build overrides this binding.
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
        // No external state to release -- the handle is the key itself,
        // not a reference to anything stored elsewhere.
    }
}
