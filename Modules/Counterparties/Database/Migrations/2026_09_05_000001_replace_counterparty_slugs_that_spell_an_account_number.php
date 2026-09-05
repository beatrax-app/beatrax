<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;
use Modules\Core\Public\Support\UniqueSlug;
use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;

// Where a statement named nobody, the resolver used the IBAN as the display
// name, and the slug derives from the display name — so the account number
// became the URL segment of /counterparties/{slug} and a plaintext column
// beside the sealed `iban` it copies. This is the forward pass that renames
// those rows; the resolver no longer writes one.
//
// The slug is the only evidence available: `display_name` and `iban` are
// AEAD-sealed, this runs before any device has been unlocked, and the shape
// of the stored slug answers the question without a key.
return new class extends ModuleMigration
{
    public function up(): void
    {
        if (! $this->schema()->hasTable('counterparties')) {
            return;
        }

        foreach ($this->userIds() as $userId) {
            $this->reslugUser($userId);
        }
    }

    public function down(): void
    {
        // Not reversed. Putting the account number back in the address bar
        // restores the leak, and nothing identifies the row by its slug: the
        // primary key is the identity, and this never touches it.
    }

    /**
     * @return list<int>
     */
    private function userIds(): array
    {
        $connection = $this->db()->connection($this->getConnection());

        $ids = [];
        foreach ($connection->table('counterparties')->distinct()->orderBy('user_id')->get(['user_id']) as $row) {
            if (is_numeric($row->user_id)) {
                $ids[] = (int) $row->user_id;
            }
        }

        return $ids;
    }

    // Per user, because the uniqueness the walk has to respect is per user.
    // Ordered by id — not as a claim about insertion order, but because two
    // synced devices hold the same row under the same id, so an order taken
    // from it makes both devices rename the same row to the same slug.
    private function reslugUser(int $userId): void
    {
        $connection = $this->db()->connection($this->getConnection());

        /** @var list<array{id: int, slug: string}> $rows */
        $rows = [];
        /** @var array<string, true> $taken */
        $taken = [];
        foreach ($connection->table('counterparties')->where('user_id', $userId)->orderBy('id')->get(['id', 'slug']) as $row) {
            $slug = is_string($row->slug) ? $row->slug : '';
            $rows[] = ['id' => is_numeric($row->id) ? (int) $row->id : 0, 'slug' => $slug];
            $taken[$slug] = true;
        }

        $connection->transaction(function () use ($connection, $rows, $taken): void {
            foreach ($rows as $row) {
                if (! CounterpartySlugResolver::spellsAnAccountIdentifier($row['slug'])) {
                    continue;
                }

                unset($taken[$row['slug']]);
                $replacement = self::firstFree($taken);
                $taken[$replacement] = true;

                $connection->table('counterparties')->where('id', $row['id'])->update(['slug' => $replacement]);
            }
        });
    }

    // Through the resolver's own base and the shared walk, not a copy of
    // either: this writes into the unique(user_id, slug) the runtime walk
    // writes into, and a second spelling of the base or of the suffix would
    // fork every renamed row into a second one on the next import.
    /**
     * @param  array<string, true>  $taken
     */
    private static function firstFree(array $taken): string
    {
        return UniqueSlug::walk(
            CounterpartySlugResolver::OPAQUE_BASE,
            static fn (string $slug): bool => ! isset($taken[$slug]),
        );
    }
};
