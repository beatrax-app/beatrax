<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The on-disk permissions every store of secret user material is born with:
// directories owner-only so a cohabiting OS user cannot enumerate the tree,
// files owner-only so it cannot read a leaf. One decision, because a store
// that quietly widens by one digit is indistinguishable from the rest.
final class SecretFileMode
{
    public const int DIRECTORY = 0700;

    public const int FILE = 0600;
}
