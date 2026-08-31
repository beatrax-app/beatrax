<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Audit\SpatieAuditWriter;
use Modules\DevMode\Internal\Enums\CommandTier;

#[Layout('dev::layouts.dev-shell')]
final class AuditLogPage extends Component
{
    use CoercesScalars;

    #[Url(as: 'tier', except: '')]
    public string $tierFilter = '';

    #[Url(as: 'command', except: '')]
    public string $commandFilter = '';

    #[Url(as: 'before', except: null)]
    public ?int $before = null;

    public const int PAGE_SIZE = 50;

    public function clearFilters(): void
    {
        $this->tierFilter = '';
        $this->commandFilter = '';
        $this->before = null;
    }

    // The same predicate render() reads through, so the button takes exactly
    // the rows the page can show. Unscoped it destroyed history this developer
    // is not allowed to open, behind one Alpine confirm().
    public function truncateAll(DatabaseManager $db, CurrentUser $user): void
    {
        $db->connection()->table('dev_mode_audit')
            ->where('log_name', SpatieAuditWriter::LOG_NAME)
            ->where('causer_id', $user->id())
            ->delete();

        $this->tierFilter = '';
        $this->commandFilter = '';
        $this->before = null;
    }

    public function older(int $oldestRenderedId): void
    {
        if ($oldestRenderedId > 0) {
            $this->before = $oldestRenderedId;
        }
    }

    public function newer(): void
    {
        $this->before = null;
    }

    public function render(ViewFactory $views, DatabaseManager $db, CurrentUser $user): View
    {
        // Raw query builder, not Eloquent: __call forwarding trips
        // larastan-strict staticMethod.dynamicCall on limit()/whereIn().

        // Scoped to the caller like every other dev_mode_audit read. Without
        // it, a run of beatrax:regenerate-recovery-codes put another user's
        // live sheet behind this page's Copy button.
        $audit = $db->connection()->table('dev_mode_audit')
            ->where('log_name', SpatieAuditWriter::LOG_NAME)
            ->where('causer_id', $user->id());

        $this->applyFilters($audit);

        // Paging on id, not created_at: the table is append-only with a
        // monotonic id, so the order matches and sub-second ties stay stable.
        if ($this->before !== null && $this->before > 0) {
            $audit->where('id', '<', $this->before);
        }

        $rows = $audit->orderByDesc('id')->limit(self::PAGE_SIZE)->get();

        $rendered = $rows->map(fn (\stdClass $row): array => $this->mapRow($row));

        // The raw builder hands back stdClass, so the id needs narrowing
        // before larastan-strict will accept it as the cursor.
        $lastRow = $rows->last();
        $lastIdRaw = $lastRow !== null ? $lastRow->id ?? 0 : 0;
        $oldestId = self::toInt($lastIdRaw);
        $hasMore = $rows->count() === self::PAGE_SIZE;

        return $views->make('dev::livewire.audit-log-page', [
            'rows' => $rendered,
            'oldestRenderedId' => $oldestId,
            'hasMore' => $hasMore,
            'isPaged' => $this->before !== null,
        ]);
    }

    private function applyFilters(Builder $audit): void
    {
        if ($this->tierFilter !== '') {
            $audit->where('properties->tier', $this->tierFilter);
        }

        if ($this->commandFilter !== '') {
            $audit->where('properties->command', $this->commandFilter);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRow(\stdClass $row): array
    {
        $propertiesRaw = is_string($row->properties) ? json_decode($row->properties, true) : null;
        $properties = is_array($propertiesRaw) ? $propertiesRaw : [];

        $createdAt = is_string($row->created_at)
            ? CarbonImmutable::parse($row->created_at)->toIso8601String()
            : null;

        return [
            'id' => $row->id,
            'command' => is_string($properties['command'] ?? null) ? $properties['command'] : '',
            'tier' => CommandTier::fromStored($properties['tier'] ?? null),
            'exitCode' => is_int($properties['exit_code'] ?? null) ? $properties['exit_code'] : null,
            'args' => is_array($properties['args'] ?? null) ? $properties['args'] : [],
            'stdout' => is_string($properties['stdout_excerpt'] ?? null) ? $properties['stdout_excerpt'] : '',
            'error' => is_string($properties['error_excerpt'] ?? null) ? $properties['error_excerpt'] : '',
            'createdAt' => $createdAt,
        ];
    }
}
