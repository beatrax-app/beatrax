<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * `/dev/audit` audit-log page.
 *
 * Lists the most recent dev_mode_audit rows filtered by tier /
 * caller / command, with ?before=<id> cursor pagination for walking
 * back through history. Filters persist via #[Url] for back-button
 * + bookmarks.
 *
 * Table columns:
 *   command (mono) · tier chip · caller username · started_at
 *   diffForHumans · exit_code (rose if non-zero). Hover expands
 *   the row to show stdout/stderr excerpts + args JSON.
 */
#[Layout('dev::layouts.dev-shell')]
final class AuditLogPage extends Component
{
    #[Url(as: 'tier', except: '')]
    public string $tierFilter = '';

    #[Url(as: 'caller', except: '')]
    public string $callerFilter = '';

    #[Url(as: 'command', except: '')]
    public string $commandFilter = '';

    /**
     * Cursor for backward (older) pagination. When non-null the
     * render() query is constrained to rows whose id is strictly less
     * than this value, so successive Older clicks walk backward
     * through the full audit history without skipping rows that
     * arrive between requests.
     */
    #[Url(as: 'before', except: null)]
    public ?int $before = null;

    /** Page size — kept in sync with the rendered row count. */
    public const PAGE_SIZE = 50;

    public function clearFilters(): void
    {
        $this->tierFilter = '';
        $this->callerFilter = '';
        $this->commandFilter = '';
        $this->before = null;
    }

    /**
     * Walk one page older by pinning the cursor to the smallest id on
     * the currently-rendered page. Each click emits a fresh
     * ?before=<id> URL via the #[Url] binding so the back button
     * walks the operator forward through the timeline.
     */
    public function older(int $oldestRenderedId): void
    {
        if ($oldestRenderedId > 0) {
            $this->before = $oldestRenderedId;
        }
    }

    /**
     * Walk back to the newest page. Drops the cursor entirely so
     * render() returns the live top-of-history slice.
     */
    public function newer(): void
    {
        $this->before = null;
    }

    public function render(ViewFactory $views, DatabaseManager $db): View
    {
        // Use the raw query builder via DatabaseManager — sidesteps
        // the Eloquent\Builder __call → Query\Builder forwarding that
        // triggers larastan-strict `staticMethod.dynamicCall` flags
        // on `limit()` / `whereIn()`. Equivalent semantics; same
        // dev_mode_audit table.
        $audit = $db->connection()->table('dev_mode_audit')
            ->where('log_name', 'dev_mode');

        if ($this->tierFilter !== '') {
            $tierLocal = $this->tierFilter;
            $audit->where('properties->tier', $tierLocal);
        }

        if ($this->commandFilter !== '') {
            $commandLocal = $this->commandFilter;
            $audit->where('properties->command', $commandLocal);
        }

        if ($this->callerFilter !== '') {
            // Username lookup → user id (the JSON shape stores the int
            // causer_id, not the username; the username filter is more
            // useful to the operator). Use the raw query builder
            // ->value('id') so the lookup pulls a single scalar rather
            // than hydrating the full Eloquent User model.
            $callerId = $db->connection()->table('users')
                ->where('username', $this->callerFilter)
                ->value('id');
            if ($callerId !== null) {
                $audit->where('causer_id', $callerId);
            } else {
                // Unknown username — force an empty result set with a
                // self-contradictory predicate the query planner
                // collapses cheaply. Cleaner than a whereRaw('1 = 0')
                // and survives the trailing limit(50) the caller
                // applies below.
                $audit->whereNull('id')->whereNotNull('id');
            }
        }

        // Cursor pagination — Older walks back through the audit
        // history without skipping rows that arrived between requests.
        // Using id < ?before is correct because rows are append-only
        // and the id column is monotonically increasing with
        // created_at; ordering by id desc gives the same chronological
        // order as ordering by created_at desc but stable against
        // sub-second timestamp ties.
        if ($this->before !== null && $this->before > 0) {
            $audit->where('id', '<', $this->before);
        }

        $rows = $audit->orderByDesc('id')->limit(self::PAGE_SIZE)->get();

        // Hydrate username for display alongside each row. Drop null /
        // zero causer ids (system writes); their rendered username is
        // the empty string.
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
                $id = is_int($u->id) ? $u->id : (int) (is_numeric($u->id) ? $u->id : 0);
                $username = is_string($u->username) ? $u->username : '';

                return [$id => $username];
            })
            ->toArray();

        $rendered = $rows->map(function (object $row) use ($usernames): array {
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
                'username' => $usernames[$causerId] ?? '',
                'createdAt' => $createdAt,
            ];
        });

        // Cursor metadata for the Older/Newer pager.
        $oldestId = (int) ($rows->last()->id ?? 0);
        $hasMore = $rows->count() === self::PAGE_SIZE;

        return $views->make('dev::livewire.audit-log-page', [
            'rows' => $rendered,
            'oldestRenderedId' => $oldestId,
            'hasMore' => $hasMore,
            'isPaged' => $this->before !== null,
        ]);
    }
}
