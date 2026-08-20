<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\UserPreference;
use Modules\Sync\Public\Events\EntityMutated;

// The one place the per-user preference row is written. Four Livewire
// components each ran their own updateOrCreate, so capture would have been the
// same dispatch copied four times — and the fifth caller is the one that
// forgets it and puts the table back on the uncaptured backlog.
final class UserPreferenceWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
    ) {}

    // Takes the owner explicitly and bypasses the global scope, so the write
    // is correct from a console command or a queue worker where no guard is
    // bound — the same posture PotWriter takes.
    /**
     * @param  array<string, mixed>  $columns  The preference columns to write.
     */
    public function write(int $userId, array $columns): UserPreference
    {
        /** @var UserPreference $preference */
        $preference = UserPreference::query()
            ->withoutGlobalScopes()
            ->firstOrNew(['user_id' => $userId]);

        $isNew = ! $preference->exists;

        $preference->fill($columns);
        $preference->save();

        $stored = $this->storedRow((int) $preference->id);

        // A create carries the whole row, so a peer that has never held one
        // materialises the same defaults this device did; an edit carries only
        // what was written, so two devices changing different preferences each
        // keep their change instead of the later write flattening the earlier.
        $this->events->dispatch(new EntityMutated(
            table: 'user_preferences',
            pk: (int) $preference->id,
            userId: $userId,
            mutationType: $isNew ? 'create' : 'edit',
            dirtyFields: $isNew ? $stored : array_intersect_key($stored, $columns),
        ));

        return $preference;
    }

    // The row as the database actually holds it, minus the id the op already
    // carries as its pk. Read back rather than echoed from the argument so a
    // JSON column travels as the stored text, not the PHP array behind it.
    /**
     * @return array<string, mixed>
     */
    private function storedRow(int $id): array
    {
        $row = $this->db->connection()->table('user_preferences')->where('id', $id)->first();

        if ($row === null) {
            return [];
        }

        /** @var array<string, mixed> $fields */
        $fields = (array) $row;
        unset($fields['id']);

        return $fields;
    }
}
