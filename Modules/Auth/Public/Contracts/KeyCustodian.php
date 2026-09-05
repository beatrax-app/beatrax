<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Contracts;

use Modules\Auth\Public\Enums\KeyCustody;

interface KeyCustodian
{
    // Implementations that cannot reach their backing store right now
    // MUST degrade gracefully by returning the raw key unchanged -- the
    // pass-through session custody then applies, exactly as on web.
    public function store(string $rawKey): string;

    // Returns null when the custodian owns a real backing entry but
    // cannot recover the key from it. Callers MUST treat null as "no key
    // held" and never as key bytes.
    public function read(string $handle): ?string;

    // A no-op for stateless handles; safe to call with a handle whose
    // backing entry is already gone.
    public function forget(string $handle): void;

    // Where the key actually ends up, which is not what the binding intends
    // but what the platform delivers. An implementation MUST answer for the
    // machine it is running on rather than for the bundle it was built into,
    // and MUST NOT report OperatingSystem for a store that does not protect.
    public function custody(): KeyCustody;
}
