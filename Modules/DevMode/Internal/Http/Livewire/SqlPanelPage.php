<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Sql\ReadOnlySqliteConnection;
use Modules\DevMode\Internal\Sql\SchemaSnapshot;
use Modules\DevMode\Internal\Sql\SelectOnlyValidator;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Throwable;

/**
 * @link ../../../../../.docs/features/dev-mode/architecture.md
 */
#[Layout('dev::layouts.dev-shell')]
final class SqlPanelPage extends Component
{
    public string $sqlInput = '';

    public string $errorMessage = '';

    /** @var list<array<string, mixed>> */
    public array $resultRows = [];

    /** @var list<string> */
    public array $resultColumns = [];

    public ?int $rowcount = null;

    public ?int $durationMs = null;

    public function run(
        Session $session,
        CurrentUser $currentUser,
        SelectOnlyValidator $validator,
        ReadOnlySqliteConnection $connection,
        AuditWriter $audit,
    ): void {
        $this->resetResultState();

        $sql = trim($this->sqlInput);

        $error = $this->preflightError($session, $sql) ?? $this->validationError($validator, $sql);
        if ($error !== null) {
            $this->errorMessage = $error;

            return;
        }

        $result = $this->runQuery($connection, $sql);
        if ($result === null) {
            return;
        }

        $rows = $result['rows'];
        $this->rowcount = count($rows);
        $this->durationMs = $result['duration_ms'];
        $this->resultColumns = $this->columnsOf($rows);
        $this->resultRows = $this->mapRows($rows);

        $audit->recordSelectQuery(
            query: $sql,
            rowcount: count($rows),
            durationMs: $result['duration_ms'],
            callerUserId: $currentUser->id(),
        );
    }

    // Gates that reject before the SELECT-only validator runs: the
    // Advanced toggle must be on and the statement non-empty. Returns the
    // operator-facing message, or null when the statement may proceed.
    private function preflightError(Session $session, string $sql): ?string
    {
        return match (true) {
            $session->get('dev_mode.advanced') !== true => Lang::get('dev::sql.errors.advanced_off'),
            $sql === '' => Lang::get('dev::sql.errors.only_select', ['reason' => 'empty_statement']),
            default => null,
        };
    }

    private function validationError(SelectOnlyValidator $validator, string $sql): ?string
    {
        try {
            $validator->validate($sql);
        } catch (ValidationException $e) {
            return Lang::get('dev::sql.errors.only_select', ['reason' => $this->rejectReason($e)]);
        }

        return null;
    }

    private function rejectReason(ValidationException $e): string
    {
        $sqlErrors = $e->errors()['sql'] ?? null;
        if (is_array($sqlErrors) && isset($sqlErrors[0]) && is_string($sqlErrors[0])) {
            return $sqlErrors[0];
        }

        return 'unknown';
    }

    // Returns the executed result, or null after setting errorMessage: a
    // timeout notice when the engine hit its execution-time cap, otherwise
    // the engine's own error text (e.g. SQLITE_READONLY from a write that
    // slipped past the validator) so the operator can react.
    /**
     * @return array{rows: list<object>, duration_ms: int}|null
     */
    private function runQuery(ReadOnlySqliteConnection $connection, string $sql): ?array
    {
        try {
            return $connection->execute($sql);
        } catch (Throwable $e) {
            $this->errorMessage = str_contains($e->getMessage(), 'maximum execution time')
                ? Lang::get('dev::sql.errors.timeout')
                : Lang::get('dev::sql.errors.engine', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  list<object>  $rows
     * @return list<string>
     */
    private function columnsOf(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        return array_values(array_filter(
            array_keys(get_object_vars($rows[0])),
            static fn ($k): bool => is_string($k),
        ));
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function mapRows(array $rows): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            $normalised = [];
            foreach (get_object_vars($row) as $key => $value) {
                if (is_string($key)) {
                    $normalised[$key] = $value;
                }
            }
            $mapped[] = $normalised;
        }

        return $mapped;
    }

    public function browseTable(
        string $table,
        Session $session,
        CurrentUser $currentUser,
        SelectOnlyValidator $validator,
        ReadOnlySqliteConnection $connection,
        AuditWriter $audit,
        SchemaSnapshot $schema,
    ): void {
        // Browse feeds SELECT * FROM <table> LIMIT 100 through the same
        // pipeline. Assert the name is on the live schema allow-list
        // first, so a tampered payload smuggling an off-schema name is
        // rejected before the SELECT ever reaches the engine.
        $allowedNames = array_column($schema->all(), 'name');
        if (! in_array($table, $allowedNames, true)) {
            $this->resetResultState();
            $this->errorMessage = Lang::get('dev::sql.errors.unknown_table');

            return;
        }
        $safeName = '"'.str_replace('"', '""', $table).'"';
        $this->sqlInput = 'SELECT * FROM '.$safeName.' LIMIT 100';
        $this->run($session, $currentUser, $validator, $connection, $audit);
    }

    public function render(
        ViewFactory $views,
        Session $session,
        SchemaSnapshot $schema,
    ): View {
        $advancedOn = $session->get('dev_mode.advanced') === true;

        return $views->make('dev::livewire.sql-panel-page', [
            'tables' => $schema->all(),
            'advancedOn' => $advancedOn,
        ]);
    }

    private function resetResultState(): void
    {
        $this->errorMessage = '';
        $this->resultRows = [];
        $this->resultColumns = [];
        $this->rowcount = null;
        $this->durationMs = null;
    }
}
