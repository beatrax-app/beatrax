<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Audit\FinalizeRunAudit;
use Modules\DevMode\Internal\Audit\SpatieAuditWriter;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Exceptions\ProcessSpawningUnavailableException;
use Modules\DevMode\Internal\Listeners\WriteWorkerHeartbeat;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\ProcessLiveness;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Internal\Support\DevModeSession;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;

#[Layout('dev::layouts.dev-shell')]
final class ArtisanRunnerPage extends Component
{
    use DispatchesToast;

    #[Url(as: 'filter', except: 'all')]
    public string $filter = 'all';

    public function mount(
        Session $session,
        CommandSpawner $spawner,
        CurrentUser $user,
        DevCommandRegistry $registry,
        ?string $spawn = null,
    ): void {
        // Session resume never refires Login, so a stale Advanced=true would
        // survive the listener that is supposed to clear it.
        if (! $session->has(DevModeSession::ADVANCED_SEEN_KEY)) {
            $session->forget(DevModeSession::ADVANCED_KEY);
            $session->put(DevModeSession::ADVANCED_SEEN_KEY, true);
        }

        // A palette pick made off-page arrives here as ?spawn=, and must
        // clear the same guard as one made on the page.
        if (is_string($spawn) && $spawn !== '') {
            $this->spawn($spawn, [], $spawner, $user, $registry);
        }
    }

    public function setFilter(string $filter): void
    {
        if (! in_array($filter, ['all', 'running', 'failed', 'destructive'], true)) {
            return;
        }
        $this->filter = $filter;
    }

    // The fallback modal excludes destructive rows, but a hostile client can
    // still name one, so the tier check lives here rather than in the view.
    /**
     * @param  array<string, mixed>  $args
     */
    public function spawn(
        string $command,
        array $args,
        CommandSpawner $spawner,
        CurrentUser $user,
        DevCommandRegistry $registry,
    ): void {
        try {
            $spec = $registry->find($command);
        } catch (\InvalidArgumentException) {
            $this->toast(Lang::get('dev::runner.toast.unknown_command', ['command' => $command]));

            return;
        }

        if (! $spec->tier->reachesThePalette()) {
            $this->dispatch('triple-gate:open', command: $command, args: $args);

            return;
        }

        // A palette pick dispatches `args: []`, so a SAFE command with a
        // required arg would abort inside Symfony Console with nothing
        // surfaced to the operator.
        $missing = $this->missingRequiredArgs($spec->argsSchema, $args);
        if ($missing !== []) {
            $this->toast(
                Lang::get('dev::runner.toast.missing_args', [
                    'command' => $command,
                    'noun' => Lang::choice('dev::runner.toast.arg', count($missing)),
                    'list' => implode(', ', $missing),
                ]),
            );

            return;
        }

        try {
            $runId = $spawner->start($command, $args, $user->id(), CommandTier::Safe);
        } catch (ProcessSpawningUnavailableException $e) {
            $this->toast($e->readerMessage());

            return;
        }

        $this->toast(Lang::get('dev::runner.toast.started', ['command' => $command, 'runId' => $runId]));
    }

    /**
     * @param  list<ArgSpec>  $schema
     * @param  array<string, mixed>  $args
     * @return list<string>
     */
    private function missingRequiredArgs(array $schema, array $args): array
    {
        $missing = [];
        foreach ($schema as $arg) {
            if (! in_array('required', $arg->rules, true)) {
                continue;
            }
            $value = $args[$arg->name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $arg->label !== '' ? $arg->label : $arg->name;
            }
        }

        return $missing;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    #[On('spawn-command')]
    public function onSpawnCommand(
        string $name,
        array $args,
        string $tier,
        CommandSpawner $spawner,
        CurrentUser $user,
        DevCommandRegistry $registry,
    ): void {
        // `$tier` is caller-supplied and deliberately ignored; spawn()
        // re-reads the authoritative tier from the registry.
        unset($tier);

        $this->spawn($name, $args, $spawner, $user, $registry);
    }

    // Past the RunRegistry's 24h TTL the audit row survives but its spawn
    // payload does not, so an old row is visible history and nothing more.
    public function rerun(
        string $runId,
        RunRegistry $registry,
        CommandSpawner $spawner,
        CurrentUser $user,
    ): void {
        $record = $registry->find($runId);
        if ($record === null) {
            $this->toast(Lang::get('dev::runner.toast.run_expired'));

            return;
        }

        if ($record->tier === CommandTier::Destructive) {
            $this->dispatch(
                'triple-gate:open',
                command: $record->command,
                args: $record->args,
            );

            return;
        }

        try {
            $newRunId = $spawner->start($record->command, $record->args, $user->id(), CommandTier::Safe);
        } catch (ProcessSpawningUnavailableException $e) {
            $this->toast($e->readerMessage());

            return;
        }

        $this->toast(Lang::get('dev::runner.toast.reran', ['command' => $record->command, 'runId' => $newRunId]));
    }

    public function render(
        ViewFactory $views,
        CurrentUser $user,
        DevCommandRegistry $registry,
        CacheRepository $cache,
        Clock $clock,
        DatabaseManager $db,
        RunRegistry $runRegistry,
        ProcessLiveness $liveness,
        FinalizeRunAudit $finalize,
    ): View {
        $userId = $user->id();

        // The SSE done branch is what finalizes the eager audit row, so a run
        // whose stream the operator never opened stays "running" forever.
        $this->sweepPendingRuns($db, $userId, $runRegistry, $liveness, $finalize);

        // The query builder rather than Eloquent: Builder's __call forwarding
        // trips larastan-strict staticMethod.dynamicCall on limit()/whereIn().
        $audit = $db->connection()->table('dev_mode_audit')
            ->where('log_name', SpatieAuditWriter::LOG_NAME)
            ->where('causer_id', $userId)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        $runs = $audit->map(fn (object $row): array => $this->mapAuditRow($row));

        $filtered = match ($this->filter) {
            'running' => $runs->where('status', 'running')->values(),
            'failed' => $runs->filter(fn (array $r): bool => is_int($r['exitCode'] ?? null) && $r['exitCode'] !== 0)->values(),
            'destructive' => $runs->where('tier', CommandTier::Destructive)->values(),
            default => $runs,
        };

        return $views->make('dev::livewire.artisan-runner-page', [
            'runs' => $filtered,
            'safeCommands' => $registry->safe(),
            'workerAlive' => $this->workerIsAlive($cache, $clock),
            'filter' => $this->filter,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAuditRow(object $row): array
    {
        $vars = get_object_vars($row);
        $properties = $this->decodeProperties($vars['properties'] ?? null);

        $exitCode = is_int($properties['exit_code'] ?? null) ? $properties['exit_code'] : null;
        $finishedAt = is_string($properties['finished_at'] ?? null) ? $properties['finished_at'] : null;
        $argsRaw = $properties['args'] ?? null;
        $args = is_array($argsRaw) ? $argsRaw : [];
        $cancelled = ($args['__cancelled'] ?? false) === true;
        unset($args['__cancelled']);

        return [
            'runId' => $this->resolveRunId($properties, $vars),
            'command' => is_string($properties['command'] ?? null) ? $properties['command'] : '',
            'args' => $args,
            'tier' => CommandTier::fromStored($properties['tier'] ?? null),
            'status' => $this->runStatus($cancelled, $exitCode, $finishedAt),
            'startedAt' => $this->resolveStartedAt($properties, $vars),
            'exitCode' => $exitCode,
            'excerpt' => is_string($properties['stdout_excerpt'] ?? null) ? $properties['stdout_excerpt'] : null,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeProperties(mixed $rawProperties): array
    {
        $decoded = is_string($rawProperties) ? json_decode($rawProperties, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function runStatus(bool $cancelled, ?int $exitCode, ?string $finishedAt): string
    {
        return match (true) {
            $cancelled => 'cancelled',
            $exitCode === null && $finishedAt === null => 'running',
            default => 'done',
        };
    }

    // The spawn-time UUID wins because data-run-id has to match the SSE
    // route argument; the audit row id is only a pre-eager-write fallback.
    /**
     * @param  array<array-key, mixed>  $properties
     * @param  array<array-key, mixed>  $vars
     */
    private function resolveRunId(array $properties, array $vars): string
    {
        $runIdFromProps = is_string($properties['run_id'] ?? null) ? $properties['run_id'] : '';
        if ($runIdFromProps !== '') {
            return $runIdFromProps;
        }

        $idRaw = $vars['id'] ?? null;

        return match (true) {
            is_int($idRaw) => (string) $idRaw,
            is_string($idRaw) => $idRaw,
            default => '',
        };
    }

    /**
     * @param  array<array-key, mixed>  $properties
     * @param  array<array-key, mixed>  $vars
     */
    private function resolveStartedAt(array $properties, array $vars): ?string
    {
        $startedAtIso = is_string($properties['started_at'] ?? null) ? $properties['started_at'] : null;
        if ($startedAtIso !== null) {
            return $startedAtIso;
        }

        $createdAtRaw = $vars['created_at'] ?? null;

        return is_string($createdAtRaw)
            ? CarbonImmutable::parse($createdAtRaw)->toIso8601String()
            : null;
    }

    private function workerIsAlive(CacheRepository $cache, Clock $clock): bool
    {
        $heartbeatTs = $cache->get(WriteWorkerHeartbeat::CACHE_KEY);
        $heartbeatTs = is_int($heartbeatTs) ? $heartbeatTs : null;

        return $heartbeatTs !== null
            && $heartbeatTs > ($clock->now()->getTimestamp() - WriteWorkerHeartbeat::TTL_SECONDS);
    }

    // A row whose RunRegistry entry has TTL'd has no PID to probe, so it is
    // left pending rather than given an invented finish marker.
    private function sweepPendingRuns(
        DatabaseManager $db,
        int $userId,
        RunRegistry $runRegistry,
        ProcessLiveness $liveness,
        FinalizeRunAudit $finalize,
    ): void {
        $pending = $db->connection()->table('dev_mode_audit')
            ->where('log_name', SpatieAuditWriter::LOG_NAME)
            ->where('causer_id', $userId)
            ->whereNull('properties->finished_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        foreach ($pending as $row) {
            $vars = get_object_vars($row);
            $propertiesRaw = $vars['properties'] ?? null;
            if (! is_string($propertiesRaw)) {
                continue;
            }
            $decoded = json_decode($propertiesRaw, true);
            if (! is_array($decoded)) {
                continue;
            }

            $runIdRaw = $decoded['run_id'] ?? null;
            if (! is_string($runIdRaw) || $runIdRaw === '') {
                continue;
            }

            $record = $runRegistry->find($runIdRaw);
            if ($record === null) {
                continue;
            }
            if ($liveness->isAlive($record->pid)) {
                continue;
            }

            // exitCode is null because the bash detach lost it; the run card
            // renders "exit ?" rather than claiming a code it never saw.
            ($finalize)($runIdRaw, null, false);
        }
    }
}
