<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Contracts;

/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
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
}
