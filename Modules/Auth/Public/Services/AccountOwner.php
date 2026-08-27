<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

// Who may manage the other people on this install. The owner is the account
// created first: signup and the install command both mint it, and
// AddUserAction only ever adds partners after it.
//
// is_developer cannot stand in for this. Developer mode is deliberately
// self-settable from /settings under co-equal household trust, so gating the
// password-reset and recovery-code surfaces on it let a partner promote
// themselves and take the owner's account over in two clicks.
final class AccountOwner
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function isOwner(User $user): bool
    {
        return $user->id === $this->ownerId();
    }

    public function ownerId(): ?int
    {
        $id = $this->db->connection()->table('users')->min('id');

        return is_numeric($id) ? (int) $id : null;
    }
}
