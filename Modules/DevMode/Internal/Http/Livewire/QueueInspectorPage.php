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
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Queue\QueueActions;
use Modules\DevMode\Internal\Queue\QueueRowLoader;
use Modules\DevMode\Internal\Services\DevModeFlag;

/**
 * @phpstan-import-type QueueRow from QueueRowLoader
 */
#[Layout('dev::layouts.dev-shell')]
final class QueueInspectorPage extends Component
{
    use DispatchesToast;

    public const TABS = ['pending', 'failed', 'batches'];

    public string $tab = 'pending';

    // Pending ids are ints rendered as strings; the other tabs hold uuids.
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
        $this->toast(Lang::get('dev::queue.toast.pending_deleted'));
    }

    public function retry(string $uuid, QueueActions $actions): void
    {
        $actions->retryFailed($uuid);
        $this->toast(Lang::get('dev::queue.toast.failed_requeued'));
    }

    public function forget(string $uuid, QueueActions $actions): void
    {
        $actions->forgetFailed($uuid);
        $this->toast(Lang::get('dev::queue.toast.failed_removed'));
    }

    public function cancel(string $batchId, QueueActions $actions): void
    {
        $actions->cancelBatch($batchId);
        $this->toast(Lang::get('dev::queue.toast.batch_cancelled'));
    }

    public function deleteBatch(string $batchId, QueueActions $actions): void
    {
        $actions->deleteBatch($batchId);
        $this->toast(Lang::get('dev::queue.toast.batch_deleted'));
    }

    public function retryFailures(string $batchId, QueueActions $actions): void
    {
        $actions->retryBatchFailures($batchId);
        $this->toast(Lang::get('dev::queue.toast.batch_failures_requeued'));
    }

    public function bulkRetryConfirm(QueueActions $actions): void
    {
        if ($this->selected === []) {
            return;
        }
        if ($this->tab !== 'failed') {
            return;
        }
        $actions->bulkRetry($this->selected);
        $this->selected = [];
        $this->toast(Lang::get('dev::queue.toast.failed_jobs_requeued'));
    }

    // Only the tab and a count cross the wire; the selection stays in
    // $selected, so a tampered gate confirm cannot widen it.
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

    // The gate is re-validated server-side, and keyed on the command string
    // so an unrelated confirm cannot land here and delete queue rows.
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
            $this->toast(Lang::get('dev::queue.toast.bulk_refused', ['reason' => $refusal]));

            return;
        }

        $kindRaw = $args['tab'] ?? null;
        $kind = is_string($kindRaw) ? $kindRaw : $this->tab;
        if (! in_array($kind, self::TABS, true)) {
            return;
        }

        $actions->bulkDelete($this->selected, $kind);
        $this->selected = [];
        $this->toast(Lang::get('dev::queue.toast.rows_deleted'));
    }

    private function bulkDeleteRefusal(DevModeFlag $devMode, Session $session, string $confirmedTyped): ?string
    {
        return match (true) {
            ! $devMode->isOn() => Lang::get('dev::queue.refusal.dev_mode_off'),
            $session->get('dev_mode.advanced') !== true => Lang::get('dev::queue.refusal.advanced_off'),
            ! hash_equals('beatrax', $confirmedTyped) => Lang::get('dev::queue.refusal.token_mismatch'),
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

        // The query builder rather than Eloquent: Builder's __call forwarding
        // trips larastan-strict staticMethod.dynamicCall.
        $pendingCount = $connection->table('jobs')->count();
        $failedCount = $connection->table('failed_jobs')->count();
        $batchesCount = $connection->table('job_batches')
            ->whereNull('cancelled_at')
            ->whereNull('finished_at')
            ->count();

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
