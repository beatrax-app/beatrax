<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;

// The owner is the account created first; AddUserAction only adds partners
// after it. is_developer cannot stand in, because /settings lets any user set
// that on themselves by design -- gating the password-reset and recovery-code
// surfaces on it let a partner take the owner's account over in two clicks.
final readonly class AccountOwner
{
    public function __construct(
        private DatabaseManager $db,
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
