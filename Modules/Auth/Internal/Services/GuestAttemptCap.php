<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Services;

// How often an unauthenticated caller may try a credential against one account.
// Shared on purpose: the recovery code and the account password reach the same
// account from the same screenless place, and a household that has learned one
// number should not have to learn a second.
final class GuestAttemptCap
{
    public const int PER_MINUTE = 5;
}
