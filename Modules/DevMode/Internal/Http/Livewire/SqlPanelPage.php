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

        if ($session->get('dev_mode.advanced') !== true) {
            $this->errorMessage = 'Enable Advanced (Dev Mode → Advanced) to run queries.';

            return;
        }

        $sql = trim($this->sqlInput);
        if ($sql === '') {
            $this->errorMessage = 'Only SELECT statements are allowed. Reject reason: empty_statement.';

            return;
        }

        try {
            $validator->validate($sql);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $sqlErrors = $errors['sql'] ?? null;
            $reason = 'unknown';
            if (is_array($sqlErrors) && isset($sqlErrors[0]) && is_string($sqlErrors[0])) {
                $reason = $sqlErrors[0];
            }
            $this->errorMessage = 'Only SELECT statements are allowed. Reject reason: '.$reason.'.';

            return;
        }

        try {
            $result = $connection->execute($sql);
        } catch (Throwable $e) {
            $this->errorMessage = 'Query exceeded the 5-second timeout. Refine your query and try again.';
            // For other engine errors (e.g. SQLITE_READONLY surfaced
            // from a write that slipped past the validator) the
            // message body is the engine's own error text — visible
            // in the panel so the operator can react.
            if (! str_contains($e->getMessage(), 'maximum execution time')) {
                $this->errorMessage = 'SQL error: '.$e->getMessage();
            }

            return;
        }

        $rows = $result['rows'];
        $this->rowcount = count($rows);
        $this->durationMs = $result['duration_ms'];
        $this->resultColumns = $rows === []
            ? []
            : array_values(array_filter(
                array_keys(get_object_vars($rows[0])),
                static fn ($k): bool => is_string($k),
            ));
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
        $this->resultRows = $mapped;

        $audit->recordSelectQuery(
            query: $sql,
            rowcount: $this->rowcount,
            durationMs: $this->durationMs,
            callerUserId: $currentUser->id(),
        );
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
            $this->errorMessage = 'Unknown table.';

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
