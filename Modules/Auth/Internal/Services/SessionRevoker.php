<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

// Deleting the `sessions` rows severs nothing on its own: the remember-me
// recaller re-authenticates the same cookie into a brand-new session, so an
// account taken over stayed taken over across every password change.
final readonly class SessionRevoker
{
    // What SessionGuard::cycleRememberToken() mints, so a rotation from here
    // and one from a logout are the same shape of value.
    private const int REMEMBER_TOKEN_LENGTH = 60;

    public function __construct(
        private DatabaseManager $db,
    ) {}

    public function revokeAllFor(int $userId, ?string $keepSessionId = null): void
    {
        $sessions = $this->db->connection()->table('sessions')->where('user_id', $userId);

        if ($keepSessionId !== null) {
            $sessions->where('id', '!=', $keepSessionId);
        }

        $sessions->delete();

        $this->db->connection()->table('users')
            ->where('id', $userId)
            ->update(['remember_token' => Str::random(self::REMEMBER_TOKEN_LENGTH)]);
    }
}
