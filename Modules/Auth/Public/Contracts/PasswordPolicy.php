<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Contracts;

// The one length every passphrase gate measures against: the three actions, the
// console reset, the two settings pages, the mobile bootstrap, and the checklist
// the browser ticks while the reader types. A client rule that disagrees with
// the server rule is worse than no client rule at all.
final class PasswordPolicy
{
    public const int MINIMUM_LENGTH = 12;
}
