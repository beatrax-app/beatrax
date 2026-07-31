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

// Lists the most recent dev_mode_audit rows filtered by tier/caller/
// command, with ?before=<id> cursor pagination. Table columns: command
// (mono), tier chip, caller username, started_at, exit_code (rose if
// non-zero); hover expands to show stdout/stderr excerpts + args JSON.
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

    // When non-null, render() constrains rows to id strictly less than
    // this value, so successive Older clicks walk backward through the
    // full audit history without skipping rows arriving between requests.
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

    // Wired to the "Clear all" button, whose Alpine confirm() gate is
    // the user-facing speed bump; this handler unconditionally truncates
    // every developer's rows (dev_mode_audit is shared across all of
    // them), matching the "Clear all" copy's stated intent.
    public function truncateAll(DatabaseManager $db): void
    {
        $db->connection()->table('dev_mode_audit')->delete();

        $this->tierFilter = '';
        $this->callerFilter = '';
        $this->commandFilter = '';
        $this->before = null;
    }

    // Pins the cursor to the smallest id on the currently-rendered page;
    // each click emits a fresh ?before=<id> URL so the back button walks
    // the operator forward through the timeline.
    public function older(int $oldestRenderedId): void
    {
        if ($oldestRenderedId > 0) {
            $this->before = $oldestRenderedId;
        }
    }

    // Drops the cursor entirely so render() returns the live
    // top-of-history slice.
    public function newer(): void
    {
        $this->before = null;
    }

    public function render(ViewFactory $views, DatabaseManager $db): View
    {
        // Raw query builder via DatabaseManager sidesteps the
        // Eloquent\Builder __call forwarding that triggers
        // larastan-strict staticMethod.dynamicCall on limit()/whereIn().
        $audit = $db->connection()->table('dev_mode_audit')
            ->where('log_name', 'dev_mode');

        $this->applyFilters($audit, $db);

        // id < ?before walks back without skipping rows that arrive
        // between requests: rows are append-only with a monotonically
        // increasing id, so ordering by id desc matches created_at
        // desc but stays stable against sub-second timestamp ties.
        if ($this->before !== null && $this->before > 0) {
            $audit->where('id', '<', $this->before);
        }

        $rows = $audit->orderByDesc('id')->limit(self::PAGE_SIZE)->get();

        $usernames = $this->resolveUsernames($rows, $db);
        $rendered = $rows->map(fn (\stdClass $row): array => $this->mapRow($row, $usernames));

        // Cursor metadata for the Older/Newer pager. The raw query
        // builder returns rows as stdClass; the id column resolves
        // to int on the SQLite + spatie/activitylog schema. Narrow
        // defensively so larastan-strict is happy.
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

    // Applies the tier / command / caller-username filters in place. An
    // unknown username forces an empty result set with a self-
    // contradictory predicate rather than whereRaw('1 = 0').
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

        // The JSON shape stores causer_id (int), not username, so resolve
        // the operator-facing username filter to an id via a scalar
        // ->value() lookup rather than hydrating a User.
        $callerId = $db->connection()->table('users')
            ->where('username', $this->callerFilter)
            ->value('id');
        if ($callerId !== null) {
            $audit->where('causer_id', $callerId);
        } else {
            $audit->whereNull('id')->whereNotNull('id');
        }
    }

    // Hydrates a causer-id → username map for the rendered page. Null /
    // zero causer ids (system writes) are dropped; their rendered
    // username falls back to the empty string in mapRow().
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
            'tier' => is_string($properties['tier'] ?? null) ? $properties['tier'] : 'safe',
            'exitCode' => is_int($properties['exit_code'] ?? null) ? $properties['exit_code'] : null,
            'args' => is_array($properties['args'] ?? null) ? $properties['args'] : [],
            'stdout' => is_string($properties['stdout_excerpt'] ?? null) ? $properties['stdout_excerpt'] : '',
            'error' => is_string($properties['error_excerpt'] ?? null) ? $properties['error_excerpt'] : '',
            'username' => $usernames[$causerId] ?? '',
            'createdAt' => $createdAt,
        ];
    }
}
