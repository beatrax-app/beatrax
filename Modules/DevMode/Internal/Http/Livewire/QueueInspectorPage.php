<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Queue\QueueActions;
use Modules\DevMode\Internal\Services\DevModeFlag;

/**
 * `/dev/queue/{tab}` — three-tab queue inspector.
 *
 * Deep-linkable URLs: pending / failed / batches each resolve to
 * this single component with the tab driven by the route param. The
 * `/dev/queue` bare URL redirects to `/dev/queue/pending` (registered
 * in Routes/web.php).
 *
 * Per-row actions:
 *   - Pending (`jobs`):                delete
 *   - Failed  (`failed_jobs`):         retry, delete (forget)
 *   - Batches (`job_batches`):         retry-failures, cancel, delete
 *
 * Bulk actions:
 *   - Bulk retry  → single-confirm modal (`bulk-retry`).
 *   - Bulk delete → triple-gate via the shared TripleGateModal
 *                   (`triple-gate:open`); the modal's confirmed
 *                   event listener calls executeBulkDelete(), which
 *                   re-validates all three gates before delegating
 *                   to QueueActions::bulkDelete.
 *
 * Count tiles: three header tiles (Pending / Failed / Batches)
 * driven by raw query-builder counts (Eloquent\\Builder dynamic-call
 * narrowing is banned by the larastan-strict-rules profile).
 * wire:poll.5s lives in the Blade.
 *
 * Inline JSON payload viewer: every payload (jobs + failed_jobs)
 * passes through RedactSecretsProcessor::scrub() at render time so
 * Bearer / JWT / OAuth-secret literals never reach the browser. The
 * viewer is opt-in via expandedRowId (one expanded row per page).
 *
 * Method-DI on every action — Livewire components never receive
 * constructor DI per the project's larastan-strict-rules profile.
 *
 * @phpstan-type QueueRow array{
 *   key: string,
 *   queue?: string,
 *   uuid?: string,
 *   name?: string,
 *   attempts?: int,
 *   pendingJobs?: int,
 *   failedJobs?: int,
 *   cancelledAt?: int|null,
 *   finishedAt?: int|null,
 *   createdAt?: int|null,
 *   failedAt?: \Carbon\CarbonInterface|null,
 *   payload?: string|null,
 *   options?: string|null,
 * }
 */
#[Layout('dev::layouts.dev-shell')]
final class QueueInspectorPage extends Component
{
    public const TABS = ['pending', 'failed', 'batches'];

    /** Active tab. Driven by the route param (mount injects it). */
    public string $tab = 'pending';

    /**
     * Bulk-selected row keys. Pending tab holds string-encoded int ids;
     * Failed tab holds uuids; Batches tab holds batch UUIDs.
     *
     * @var list<string>
     */
    #[Url]
    public array $selected = [];

    /**
     * Expanded-payload row id. `null` = none expanded.
     */
    public ?string $expandedRowId = null;

    /**
     * mount() runs once per page load — Livewire 4 wires route params
     * via the parameter name. Allow-list guard mirrors the same
     * tab-switching pattern used elsewhere in the codebase (e.g.
     * DriftPage::setTab).
     */
    public function mount(string $tab = 'pending'): void
    {
        if (! in_array($tab, self::TABS, true)) {
            $tab = 'pending';
        }
        $this->tab = $tab;
    }

    public function togglePayloadExpand(string $id): void
    {
        $this->expandedRowId = $this->expandedRowId === $id ? null : $id;
    }

    public function delete(int $id, QueueActions $actions): void
    {
        $actions->deletePending($id);
        $this->dispatch('toast', message: 'Pending job deleted');
    }

    public function retry(string $uuid, QueueActions $actions): void
    {
        $actions->retryFailed($uuid);
        $this->dispatch('toast', message: 'Failed job re-queued');
    }

    public function forget(string $uuid, QueueActions $actions): void
    {
        $actions->forgetFailed($uuid);
        $this->dispatch('toast', message: 'Failed job removed');
    }

    public function cancel(string $batchId, QueueActions $actions): void
    {
        $actions->cancelBatch($batchId);
        $this->dispatch('toast', message: 'Batch cancelled');
    }

    public function deleteBatch(string $batchId, QueueActions $actions): void
    {
        $actions->deleteBatch($batchId);
        $this->dispatch('toast', message: 'Batch deleted');
    }

    public function retryFailures(string $batchId, QueueActions $actions): void
    {
        $actions->retryBatchFailures($batchId);
        $this->dispatch('toast', message: 'Batch failures re-queued');
    }

    /**
     * Bulk-retry path. Non-destructive — Blade dispatches a
     * single-confirm Flux modal; this method runs on confirmation.
     */
    public function bulkRetryConfirm(QueueActions $actions): void
    {
        if ($this->selected === []) {
            return;
        }
        // Only the Failed tab surfaces bulk-retry — pending and
        // batches do not offer the retry-all affordance.
        if ($this->tab !== 'failed') {
            return;
        }
        $actions->bulkRetry($this->selected);
        $this->selected = [];
        $this->dispatch('toast', message: 'Failed jobs re-queued');
    }

    /**
     * Bulk-delete path. DESTRUCTIVE — Blade dispatches
     * `triple-gate:open` with the current tab + selected ids forwarded
     * so the TripleGate modal's confirm event arrives back here in
     * executeBulkDelete().
     */
    public function bulkDeleteRequest(): void
    {
        if ($this->selected === []) {
            return;
        }
        $this->dispatch(
            'triple-gate:open',
            command: 'queue.bulk.delete',
            args: ['tab' => $this->tab, 'count' => count($this->selected)],
        );
    }

    /**
     * Triple-gate has fired the `triple-gate:confirmed` event. The
     * gate has already validated DevModeFlag + session.advanced +
     * typed-name; this listener re-validates those same three gates
     * before performing the destructive write — defense-in-depth in
     * case a future TripleGateModal bug ever lets a confirmed event
     * escape without full validation (e.g. an exception in confirm()
     * that fires the dispatch before throwing). The pattern mirrors
     * DestructiveSpawnController's re-validation on the artisan side.
     *
     * Discriminates on the command string so unrelated gate confirms
     * (artisan-tier destructive) cannot accidentally delete queue
     * rows.
     *
     * @param  array<string, mixed>  $args
     */
    #[On('triple-gate:confirmed')]
    public function executeBulkDelete(
        QueueActions $actions,
        DevModeFlag $devMode,
        Session $session,
        string $command = '',
        array $args = [],
        string $confirmed_typed = '',
    ): void {
        if ($command !== 'queue.bulk.delete') {
            return;
        }
        if ($this->selected === []) {
            return;
        }

        // Re-validate all three triple-gate checks server-side: Dev
        // Mode env flag, session Advanced toggle, and a timing-safe
        // comparison of the typed app-name confirmation token.
        if (! $devMode->isOn()) {
            $this->dispatch('toast', message: 'Bulk delete refused — Dev Mode is OFF.');

            return;
        }
        if ($session->get('dev_mode.advanced') !== true) {
            $this->dispatch('toast', message: 'Bulk delete refused — Advanced toggle is OFF.');

            return;
        }
        if (! hash_equals('beatrax', $confirmed_typed)) {
            $this->dispatch('toast', message: 'Bulk delete refused — typed confirmation token mismatch.');

            return;
        }

        $kindRaw = $args['tab'] ?? null;
        $kind = is_string($kindRaw) ? $kindRaw : $this->tab;
        if (! in_array($kind, self::TABS, true)) {
            return;
        }

        $actions->bulkDelete($this->selected, $kind);
        $this->selected = [];
        $this->dispatch('toast', message: 'Selected rows deleted');
    }

    public function render(
        ViewFactory $views,
        RedactSecretsProcessor $scrub,
        DatabaseManager $db,
    ): View {
        $connection = $db->connection();

        // Count tiles — all three counts regardless of active tab.
        // Use the raw query builder per the larastan-strict pattern
        // (Eloquent\\Builder __call → Query\\Builder forwarding
        // triggers staticMethod.dynamicCall flags).
        $pendingCount = $connection->table('jobs')->count();
        $failedCount = $connection->table('failed_jobs')->count();
        $batchesCount = $connection->table('job_batches')
            ->whereNull('cancelled_at')
            ->whereNull('finished_at')
            ->count();

        // Per-tab row set. Read-only; the raw query builder returns
        // stdClass rows which the Blade view consumes via the
        // normalised array shape below.
        $rows = $this->loadRows($db);

        $expandedPayload = null;
        if ($this->expandedRowId !== null) {
            $expandedPayload = $this->renderExpandedPayload($scrub, $rows);
        }

        return $views->make('dev::livewire.queue-inspector-page', [
            'tab' => $this->tab,
            'rows' => $rows,
            'pendingCount' => $pendingCount,
            'failedCount' => $failedCount,
            'batchesCount' => $batchesCount,
            'expandedRowId' => $this->expandedRowId,
            'expandedPayload' => $expandedPayload,
            'selected' => $this->selected,
        ]);
    }

    /**
     * Load the rows for the active tab in a normalised shape. Using
     * the raw query builder + an explicit array mapper keeps the
     * larastan-strict-rules profile happy (no Eloquent dynamic call
     * narrowing) AND gives the Blade a single, predictable contract.
     *
     * @return list<QueueRow>
     */
    private function loadRows(DatabaseManager $db): array
    {
        $connection = $db->connection();

        return match ($this->tab) {
            'failed' => $this->mapFailedRows($connection->table('failed_jobs')
                ->orderByDesc('id')
                ->limit(100)
                ->get()),
            'batches' => $this->mapBatchRows($connection->table('job_batches')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()),
            default => $this->mapPendingRows($connection->table('jobs')
                ->orderByDesc('id')
                ->limit(100)
                ->get()),
        };
    }

    /**
     * @param  Collection<int, \stdClass>  $raw
     * @return list<QueueRow>
     */
    private function mapPendingRows($raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            // get_object_vars() converts the stdClass row to an array so
            // larastan-strict-rules does not flag the dynamic property
            // access on the raw query builder's per-column properties.
            $vars = get_object_vars($row);
            $idRaw = $vars['id'] ?? null;
            $key = is_int($idRaw) ? (string) $idRaw : (is_string($idRaw) ? $idRaw : '');
            $queue = $vars['queue'] ?? null;
            $attempts = $vars['attempts'] ?? null;
            $createdAt = $vars['created_at'] ?? null;
            $payload = $vars['payload'] ?? null;
            $out[] = [
                'key' => $key,
                'queue' => is_string($queue) ? $queue : '',
                'attempts' => is_int($attempts) ? $attempts : 0,
                'createdAt' => is_int($createdAt) ? $createdAt : null,
                'payload' => is_string($payload) ? $payload : null,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, \stdClass>  $raw
     * @return list<QueueRow>
     */
    private function mapFailedRows($raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            $vars = get_object_vars($row);
            $uuidRaw = $vars['uuid'] ?? null;
            $uuid = is_string($uuidRaw) ? $uuidRaw : '';
            $failedAtRaw = $vars['failed_at'] ?? null;
            $failedAt = null;
            if (is_string($failedAtRaw)) {
                $failedAt = CarbonImmutable::parse($failedAtRaw);
            } elseif ($failedAtRaw instanceof \DateTimeInterface) {
                $failedAt = CarbonImmutable::instance($failedAtRaw);
            }
            $queue = $vars['queue'] ?? null;
            $payload = $vars['payload'] ?? null;

            $out[] = [
                'key' => $uuid,
                'uuid' => $uuid,
                'queue' => is_string($queue) ? $queue : '',
                'failedAt' => $failedAt,
                'payload' => is_string($payload) ? $payload : null,
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, \stdClass>  $raw
     * @return list<QueueRow>
     */
    private function mapBatchRows($raw): array
    {
        $out = [];
        foreach ($raw as $row) {
            $vars = get_object_vars($row);
            $idRaw = $vars['id'] ?? null;
            $id = is_string($idRaw) ? $idRaw : '';
            $name = $vars['name'] ?? null;
            $pendingJobs = $vars['pending_jobs'] ?? null;
            $failedJobs = $vars['failed_jobs'] ?? null;
            $cancelledAt = $vars['cancelled_at'] ?? null;
            $finishedAt = $vars['finished_at'] ?? null;
            $createdAt = $vars['created_at'] ?? null;
            $options = $vars['options'] ?? null;
            $out[] = [
                'key' => $id,
                'name' => is_string($name) ? $name : '',
                'pendingJobs' => is_int($pendingJobs) ? $pendingJobs : 0,
                'failedJobs' => is_int($failedJobs) ? $failedJobs : 0,
                'cancelledAt' => is_int($cancelledAt) ? $cancelledAt : null,
                'finishedAt' => is_int($finishedAt) ? $finishedAt : null,
                'createdAt' => is_int($createdAt) ? $createdAt : null,
                'options' => is_string($options) ? $options : null,
            ];
        }

        return $out;
    }

    /**
     * Pretty-print the expanded row's payload after running it through
     * the redaction processor. Pending + failed rows expose a payload
     * column; batches use the options blob instead.
     *
     * @param  list<QueueRow>  $rows
     */
    private function renderExpandedPayload(RedactSecretsProcessor $scrub, array $rows): ?string
    {
        foreach ($rows as $row) {
            if ($row['key'] !== $this->expandedRowId) {
                continue;
            }

            $raw = match ($this->tab) {
                'batches' => $row['options'] ?? null,
                default => $row['payload'] ?? null,
            };

            if (! is_string($raw) || $raw === '') {
                return null;
            }

            return $scrub->scrub($this->prettyJsonString($raw));
        }

        return null;
    }

    private function prettyJsonString(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (is_string($pretty)) {
                return $pretty;
            }
        }

        return $raw;
    }
}
