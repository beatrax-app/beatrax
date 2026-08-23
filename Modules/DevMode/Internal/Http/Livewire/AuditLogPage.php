<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\DevMode\Internal\Audit\SpatieAuditWriter;
use Modules\DevMode\Internal\Enums\CommandTier;

#[Layout('dev::layouts.dev-shell')]
final class AuditLogPage extends Component
{
    use CoercesScalars;

    #[Url(as: 'tier', except: '')]
    public string $tierFilter = '';

    #[Url(as: 'caller', except: '')]
    public string $callerFilter = '';

    #[Url(as: 'command', except: '')]
    public string $commandFilter = '';

    #[Url(as: 'before', except: null)]
    public ?int $before = null;

    public const PAGE_SIZE = 50;

    public function clearFilters(): void
    {
        $this->tierFilter = '';
        $this->callerFilter = '';
        $this->commandFilter = '';
        $this->before = null;
    }

    // dev_mode_audit is shared across developers, so this wipes everyone's
    // rows. The only speed bump is the Alpine confirm() on the button.
    public function truncateAll(DatabaseManager $db): void
    {
        $db->connection()->table('dev_mode_audit')->delete();

        $this->tierFilter = '';
        $this->callerFilter = '';
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

    public function render(ViewFactory $views, DatabaseManager $db): View
    {
        // Raw query builder, not Eloquent: __call forwarding trips
        // larastan-strict staticMethod.dynamicCall on limit()/whereIn().
        $audit = $db->connection()->table('dev_mode_audit')
            ->where('log_name', SpatieAuditWriter::LOG_NAME);

        $this->applyFilters($audit, $db);

        // Paging on id, not created_at: the table is append-only with a
        // monotonic id, so the order matches and sub-second ties stay stable.
        if ($this->before !== null && $this->before > 0) {
            $audit->where('id', '<', $this->before);
        }

        $rows = $audit->orderByDesc('id')->limit(self::PAGE_SIZE)->get();

        $usernames = $this->resolveUsernames($rows, $db);
        $rendered = $rows->map(fn (\stdClass $row): array => $this->mapRow($row, $usernames));

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

    private function applyFilters(Builder $audit, DatabaseManager $db): void
    {
        if ($this->tierFilter !== '') {
            $audit->where('properties->tier', $this->tierFilter);
        }

        if ($this->commandFilter !== '') {
            $audit->where('properties->command', $this->commandFilter);
        }

        if ($this->callerFilter === '') {
            return;
        }

        // The audit row stores causer_id, not username, so the
        // operator-facing filter has to be resolved to an id first.
        $callerId = $db->connection()->table('users')
            ->where('username', $this->callerFilter)
            ->value('id');
        if ($callerId !== null) {
            $audit->where('causer_id', $callerId);
        } else {
            // Self-contradictory predicate: an unknown username must yield
            // nothing, and whereRaw('1 = 0') would need a raw expression.
            $audit->whereNull('id')->whereNotNull('id');
        }
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, string>
     */
    private function resolveUsernames(Collection $rows, DatabaseManager $db): array
    {
        $callerIds = $rows
            ->pluck('causer_id')
            ->filter(static fn ($id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values()
            ->all();

        $userRows = $db->connection()->table('users')
            ->whereIn('id', $callerIds)
            ->get(['id', 'username']);

        /** @var array<int, string> $usernames */
        $usernames = $userRows
            ->mapWithKeys(static function (object $u): array {
                $id = self::toInt($u->id);
                $username = is_string($u->username) ? $u->username : '';

                return [$id => $username];
            })
            ->toArray();

        return $usernames;
    }

    /**
     * @param  array<int, string>  $usernames
     * @return array<string, mixed>
     */
    private function mapRow(\stdClass $row, array $usernames): array
    {
        $propertiesRaw = is_string($row->properties) ? json_decode($row->properties, true) : null;
        $properties = is_array($propertiesRaw) ? $propertiesRaw : [];

        $causerId = is_int($row->causer_id) ? $row->causer_id : 0;
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
            'username' => $usernames[$causerId] ?? '',
            'createdAt' => $createdAt,
        ];
    }
}
