<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\SchemaShape;
use Throwable;

// Lives in Public so DoctorCommand and the boot listener both reach it without
// crossing into an interior, and answers in plain values so each builds its own
// result. Mirrors FtsHealthCheck, which asks the same shape of question about
// another thing the database is only assumed to be holding.
final readonly class SchemaShapeHealthCheck
{
    // Two queries over sqlite_master, on every connection the app opens. The
    // list is bounded by the schema rather than by the ledger, so naming the
    // tables costs nothing a row count would not have.
    private const int NAME_AT_MOST = 10;

    public function __construct(private DatabaseManager $db) {}

    public function label(): string
    {
        return 'schema shape';
    }

    /**
     * @return 'ok'|'warning'
     */
    public function severity(): string
    {
        return $this->result()['severity'];
    }

    public function message(): string
    {
        return $this->result()['message'];
    }

    // Two lists rather than a verdict: the banner counts them and the doctor
    // row names them, and a caller handed only a boolean would have to ask the
    // database a second time to say which half had gone.
    /**
     * @return array{cascading: list<string>, triggers: list<string>}
     */
    public function drift(): array
    {
        $connection = $this->db->connection();

        return [
            'cascading' => SchemaShape::cascadingTables($connection),
            'triggers' => SchemaShape::missingTriggers($connection),
        ];
    }

    /**
     * @return array{severity: 'ok'|'warning', message: string}
     */
    private function result(): array
    {
        try {
            $drift = $this->drift();
        } catch (Throwable) {
            return ['severity' => 'warning', 'message' => 'sqlite_master could not be read'];
        }

        // Not a blocker, because every row is still there and every total is
        // still right. What is gone is the refusal: a cascade takes a child
        // and writes no tombstone for the peer, and a dropped enum guard lets
        // an arriving value be stored in a form nothing can render.
        $problems = array_filter(
            [$this->cascadeProblem($drift['cascading']), $this->triggerProblem($drift['triggers'])],
            static fn (?string $problem): bool => $problem !== null,
        );

        if ($problems === []) {
            return ['severity' => 'ok', 'message' => 'matches what the migrations declare'];
        }

        return ['severity' => 'warning', 'message' => implode('; ', $problems)];
    }

    /**
     * @param  list<string>  $tables
     */
    private function cascadeProblem(array $tables): ?string
    {
        if ($tables === []) {
            return null;
        }

        return count($tables).' table(s) still delete children by cascade ('.self::naming($tables).')';
    }

    /**
     * @param  list<string>  $triggers
     */
    private function triggerProblem(array $triggers): ?string
    {
        if ($triggers === []) {
            return null;
        }

        return count($triggers).' enum guard trigger(s) absent ('.self::naming($triggers).')';
    }

    /**
     * @param  list<string>  $names
     */
    private static function naming(array $names): string
    {
        $shown = implode(', ', array_slice($names, 0, self::NAME_AT_MOST));

        return count($names) > self::NAME_AT_MOST ? $shown.' and more' : $shown;
    }
}
