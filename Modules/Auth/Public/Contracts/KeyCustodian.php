<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Contracts;

use Modules\Auth\Public\Enums\KeyCustody;
use Modules\Auth\Public\Exceptions\KeyCustodyRefused;

interface KeyCustodian
{
    // A store that is absent or unreachable MUST degrade to pass-through: the
    // raw key comes back unchanged and session custody applies, as on web. A
    // store that is present and refuses the write is not that case and MUST
    // throw -- the caller persists whatever this returns.
    /**
     * @throws KeyCustodyRefused
     */
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
