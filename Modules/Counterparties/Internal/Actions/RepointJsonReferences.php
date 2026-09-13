<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\JsonRowReferences;
use stdClass;

// Moves the ids a JSON value carries from one row to another, for the fold
// that removes the row they name.

// The three columns naming a counterparty were repointed because
// `counterparty_id` is greppable; a saved report's filter list is not, so it
// kept naming a removed row and counted none of the spend the fold moved.
/**
 * @link ../../../../.docs/features/sync/op-log-merge-rules.md#references-that-live-inside-a-json-value
 */
final readonly class RepointJsonReferences
{
    use CoercesScalars;

    private const int BATCH = 500;

    public function __construct(
        private DatabaseManager $db,
        private JsonRowReferences $references = new JsonRowReferences,
    ) {}

    // Every declared site naming $target, rather than a list of columns this
    // class remembers: a site added to the declaration for the merge layer's
    // sake is repointed here without anyone remembering this call exists.
    /**
     * @return list<EntityMutated> one per row rewritten, for the caller to announce
     */
    public function move(string $target, int $userId, int $from, int $to): array
    {
        $resolve = static fn (string $named, int|string $id): int|string => is_numeric($id) && (int) $id === $from ? $to : $id;

        $events = [];

        foreach ($this->references->sitesNaming($target) as $site) {
            $this->moveOneSite($site, $target, $userId, $from, $resolve, $events);
        }

        return $events;
    }

    /**
     * @param  array{table: string, column: string, path: string}  $site
     * @param  callable(string, int|string): (int|string)  $resolve
     * @param  list<EntityMutated>  $events
     */
    private function moveOneSite(array $site, string $target, int $userId, int $from, callable $resolve, array &$events): void
    {
        $after = 0;

        while (true) {
            $rows = $this->candidateRows($site, $userId, $from, $after);

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $after = max($after, self::toInt($row->id));
                $this->rewriteRow($site, $target, $row, $userId, $resolve, $events);
            }
        }
    }

    // A LIKE over the raw text, because the id's digits appear verbatim in the
    // JSON whatever shape holds them. It over-matches -- 12 matches 120 -- and
    // that is the safe direction: the rewrite below is exact and a row that
    // carried no such id is written back unchanged, which is to say not at all.
    /**
     * @param  array{table: string, column: string, path: string}  $site
     * @return array<int, stdClass>
     */
    private function candidateRows(array $site, int $userId, int $from, int $after): array
    {
        return $this->db->connection()
            ->table($site['table'])
            ->where('user_id', $userId)
            ->where('id', '>', $after)
            ->where($site['column'], 'like', '%'.$from.'%')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id', $site['column']])
            ->all();
    }

    /**
     * @param  array{table: string, column: string, path: string}  $site
     * @param  callable(string, int|string): (int|string)  $resolve
     * @param  list<EntityMutated>  $events
     */
    private function rewriteRow(array $site, string $target, stdClass $row, int $userId, callable $resolve, array &$events): void
    {
        $column = $site['column'];
        $stored = json_decode(self::toString($row->{$column} ?? null), true);

        if (! is_array($stored)) {
            return;
        }

        $rewritten = $this->references->rewrite($stored, [$site['path'] => $target], $resolve);

        if ($rewritten === $stored) {
            return;
        }

        $this->write($site['table'], self::toInt($row->id), $userId, $column, $rewritten, $events);
    }

    // The event carries the decoded value, as SaveReport's own capture does.
    // The column syncs, so a repoint the peer is never told about leaves the
    // two devices filtering on different counterparties.
    /**
     * @param  list<EntityMutated>  $events
     */
    private function write(string $table, int $id, int $userId, string $column, mixed $value, array &$events): void
    {
        $this->db->connection()
            ->table($table)
            ->where('user_id', $userId)
            ->where('id', $id)
            ->update([$column => json_encode($value, JSON_THROW_ON_ERROR)]);

        $events[] = new EntityMutated(
            table: $table,
            pk: $id,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: [$column => $value],
        );
    }
}
