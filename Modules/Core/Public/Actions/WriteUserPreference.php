<?php

declare(strict_types=1);

namespace Modules\Core\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;

// Writes preference columns onto a user's row and stamps updated_at from the
// injected clock — the single-column settings write that several components and
// services hand-rolled, each having to remember the updated_at stamp itself.
final class WriteUserPreference
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $columns
     */
    public function __invoke(int $userId, array $columns): void
    {
        $this->db->connection()
            ->table('users')
            ->where('id', $userId)
            ->update($columns + ['updated_at' => $this->clock->now()->toDateTimeString()]);
    }
}
