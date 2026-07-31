<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Queue\QueueActions;
use Modules\DevMode\Internal\Queue\QueueRowLoader;
use Modules\DevMode\Internal\Services\DevModeFlag;

/**
 * @link ../../../../../.docs/features/dev-mode/architecture.md
 *
 * @phpstan-import-type QueueRow from QueueRowLoader
 */
#[Layout('dev::layouts.dev-shell')]
final class QueueInspectorPage extends Component
{
    public const TABS = ['pending', 'failed', 'batches'];

    public string $tab = 'pending';

    // Pending tab holds string-encoded int ids; Failed tab holds uuids;
    // Batches tab holds batch UUIDs.
    /**
     * @var list<string>
     */
    #[Url]
    public array $selected = [];

    public ?string $expandedRowId = null;

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

    // Non-destructive — Blade dispatches a single-confirm Flux modal;
    // this method runs on confirmation.
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

    // DESTRUCTIVE — Blade dispatches triple-gate:open with the current
    // tab + selected ids forwarded so the TripleGate modal's confirm
    // event arrives back here in executeBulkDelete().
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

    // Re-validates the three triple-gate checks server-side (mirrors
    // DestructiveSpawnController's re-validation on the artisan side) and
    // discriminates on the command string so unrelated gate confirms
    // (artisan-tier destructive) cannot accidentally delete queue rows.
    /**
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
        if ($command !== 'queue.bulk.delete' || $this->selected === []) {
            return;
        }

        $refusal = $this->bulkDeleteRefusal($devMode, $session, $confirmed_typed);
        if ($refusal !== null) {
            $this->dispatch('toast', message: 'Bulk delete refused — '.$refusal);

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

    // The triple gate re-validated server-side: Dev Mode env flag, session
    // Advanced toggle, timing-safe compare of the typed app-name token.
    // Returns the reason to surface, or null when all three pass. The arms
    // short-circuit, so hash_equals runs only once the first two are open.
    private function bulkDeleteRefusal(DevModeFlag $devMode, Session $session, string $confirmedTyped): ?string
    {
        return match (true) {
            ! $devMode->isOn() => 'Dev Mode is OFF.',
            $session->get('dev_mode.advanced') !== true => 'Advanced toggle is OFF.',
            ! hash_equals('beatrax', $confirmedTyped) => 'typed confirmation token mismatch.',
            default => null,
        };
    }

    public function render(
        ViewFactory $views,
        RedactSecretsProcessor $scrub,
        DatabaseManager $db,
        QueueRowLoader $rowLoader,
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

        // Per-tab row set. Read-only; the collaborator maps the raw
        // query builder's stdClass rows into the normalised QueueRow
        // shape the Blade view consumes.
        $rows = $rowLoader->load($this->tab);

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

    // Pending + failed rows expose a payload column; batches use the
    // options blob instead.
    /**
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
