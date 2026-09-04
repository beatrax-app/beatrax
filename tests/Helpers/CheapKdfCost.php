<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Modules\Core\Public\Contracts\KdfCost;

// libsodium's floor for Argon2id: ~0.03 ms against MODERATE's ~500 ms.
//
// It lives under tests/ — a composer autoload-dev root, and one PHPStan and the
// comment-policy scan both exclude — rather than in a module, so no shipped
// bundle contains a class capable of weakening a derivation. Tests\TestCase
// binds it over Modules\Core\Public\Contracts\KdfCost for every test; nothing
// in Modules/ or app/ can name it.
final class CheapKdfCost implements KdfCost
{
    public function opslimit(): int
    {
        return 1;
    }

    public function memlimit(): int
    {
        return 8192;
    }
}
