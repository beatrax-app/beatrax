<?php

declare(strict_types=1);

namespace Modules\Core\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Public\Events\EntityMutated;

// Writes preference columns onto a user's row and stamps updated_at from the
// injected clock — the single-column settings write that several components and
// services hand-rolled, each having to remember the updated_at stamp itself.
final readonly class WriteUserPreference
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private Dispatcher $events,
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

        $this->announce($userId, array_keys($columns));
    }

    // For a caller that saved the model itself. The row is one reader's
    // settings mixed with one device's password and theme, so which columns
    // travel is the sync registry's answer, not this writer's — it announces
    // what changed and the capture listener drops what must not leave.
    /**
     * @param  list<string>  $columns
     */
    public function announce(int $userId, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $stored = $this->storedColumns($userId, $columns);

        if ($stored === []) {
            return;
        }

        $this->events->dispatch(new EntityMutated(
            table: 'users',
            pk: $userId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: $stored,
        ));
    }

    // Read back rather than echoed from the argument, so a JSON column travels
    // as the stored text and a cast column as the stored scalar.
    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function storedColumns(int $userId, array $columns): array
    {
        $row = $this->db->connection()->table('users')->where('id', $userId)->first($columns);

        if ($row === null) {
            return [];
        }

        /** @var array<string, mixed> $fields */
        $fields = (array) $row;

        return $fields;
    }
}
