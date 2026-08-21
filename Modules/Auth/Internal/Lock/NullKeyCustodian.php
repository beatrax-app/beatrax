<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Lock;

use Modules\Auth\Public\Contracts\KeyCustodian;

// The default custodian on web and CI: the handle IS the raw key, so the
// custody seam changes nothing until a desktop or mobile build rebinds it.
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

    public function forget(string $handle): void {}
}
