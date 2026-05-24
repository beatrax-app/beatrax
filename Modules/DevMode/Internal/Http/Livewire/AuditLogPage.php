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
use Modules\Core\Models\User;

/**
 * `/dev/audit` audit-log page (CONTEXT D-25).
 *
 * Lists the last ~20 dev_mode_audit rows filtered by tier / caller /
 * command. Filters persisted via #[Url] for back-button + bookmarks.
 *
 * Table per UI-SPEC § Dense tables:
 *   command (mono) · tier chip · caller username · started_at
 *   diffForHumans · exit_code (rose if non-zero). Hover expands the
 *   row to show stdout/stderr excerpts + args JSON.
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

    public function clearFilters(): void
    {
        $this->tierFilter = '';
        $this->callerFilter = '';
        $this->commandFilter = '';
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
            // useful to the operator).
            $user = User::query()
                ->where('username', $this->callerFilter)
                ->first();
            if ($user !== null) {
                $audit->where('causer_id', $user->id);
            } else {
                // Force empty result set for an unknown username.
                $audit->whereRaw('1 = 0');
            }
        }

        $rows = $audit->orderByDesc('created_at')->limit(50)->get();

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

        return $views->make('dev::livewire.audit-log-page', [
            'rows' => $rendered,
        ]);
    }
}
