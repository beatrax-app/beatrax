<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Illuminate\Database\DatabaseManager;
use Throwable;

// Asks of the stored rows what the merge gates ask of arriving ones: does any
// row name a row another reader owns. Those gates judged ids a peer minted
// until they were translated first, and a verdict reached on the wrong id
// leaves no trace anywhere except the row it wrote.
/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final readonly class OwnerBoundaryProbe implements Probe
{
    private const int REPORT_AT_MOST = 10;

    public function __construct(private DatabaseManager $db) {}

    public function label(): string
    {
        return 'owner boundaries';
    }

    public function run(): ProbeResult
    {
        try {
            $references = $this->ownerScopedReferences();
            $crossing = $this->crossingReferences($references);
        } catch (Throwable $e) {
            return new ProbeResult(ProbeSeverity::Warning->value,
                'The schema would not answer which references are owner-scoped.',
                ['exception' => $e::class],
            );
        }

        // Nothing to check is not a clean answer, it is the schema read coming
        // back empty -- the shape a silent pass would otherwise wear.
        if ($references === []) {
            return new ProbeResult(ProbeSeverity::Warning->value,
                'No owner-scoped reference was found, so this probe read nothing.',
                ['references' => 0],
            );
        }

        return $crossing === []
            ? new ProbeResult(ProbeSeverity::Ok->value,
                count($references).' owner-scoped references, none naming another reader\'s row',
                ['references' => count($references)])
            : new ProbeResult(ProbeSeverity::Warning->value,
                $this->sentenceFor($crossing),
                ['references' => count($references), 'crossing' => count($crossing)]);
    }

    /**
     * @param  list<string>  $crossing
     */
    private function sentenceFor(array $crossing): string
    {
        $shown = array_slice($crossing, 0, self::REPORT_AT_MOST);
        $rest = count($crossing) - count($shown);

        return 'Rows naming another reader\'s row: '.implode(', ', $shown)
            .($rest > 0 ? sprintf(' (+%d more)', $rest) : '');
    }

    // Both halves have to carry the owner for the question to mean anything: a
    // child scoped only through its parent has no user_id to disagree with.
    /**
     * @return list<array{child: string, column: string, parent: string}>
     */
    private function ownerScopedReferences(): array
    {
        $owned = $this->ownerScopedTables();
        $references = [];

        foreach (array_keys($owned) as $child) {
            foreach ($this->db->connection()->getSchemaBuilder()->getForeignKeys($child) as $foreignKey) {
                $column = $foreignKey['columns'][0] ?? '';

                if ($column !== '' && isset($owned[$foreignKey['foreign_table']])) {
                    $references[] = ['child' => $child, 'column' => $column, 'parent' => $foreignKey['foreign_table']];
                }
            }
        }

        return $references;
    }

    /**
     * @return array<string, true>
     */
    private function ownerScopedTables(): array
    {
        $schema = $this->db->connection()->getSchemaBuilder();
        $owned = [];

        foreach ($schema->getTables() as $table) {
            $name = $table['name'];

            if (! str_starts_with($name, 'sqlite_') && in_array('user_id', array_column($schema->getColumns($name), 'name'), true)) {
                $owned[$name] = true;
            }
        }

        return $owned;
    }

    // A shared row carries a null owner, and `!=` against a null is null, so
    // SQL drops it without being told to. Said here rather than as a second
    // where clause that would read as though it were doing the work.
    /**
     * @param  list<array{child: string, column: string, parent: string}>  $references
     * @return list<string>
     */
    private function crossingReferences(array $references): array
    {
        $crossing = [];

        foreach ($references as $reference) {
            $count = $this->db->connection()->table($reference['child'], 'child')
                ->join($reference['parent'].' as parent', 'parent.id', '=', 'child.'.$reference['column'])
                ->whereColumn('child.user_id', '!=', 'parent.user_id')
                ->count();

            if ($count > 0) {
                $crossing[] = sprintf('%s.%s -> %s (%d)', $reference['child'], $reference['column'], $reference['parent'], $count);
            }
        }

        return $crossing;
    }
}
