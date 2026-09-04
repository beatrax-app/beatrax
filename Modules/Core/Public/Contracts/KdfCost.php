<?php

declare(strict_types=1);

namespace Modules\Core\Public\Contracts;

// The Argon2id work factor every passphrase-stretched key in this application
// is derived at. A collaborator rather than a constant at each call site, so
// the suite can substitute a near-free cost without a single shipped class
// carrying a branch that could select one.
/**
 * @link ../../../../.docs/architecture/argon2id-cost.md
 */
interface KdfCost
{
    public function opslimit(): int;

    public function memlimit(): int;
}
