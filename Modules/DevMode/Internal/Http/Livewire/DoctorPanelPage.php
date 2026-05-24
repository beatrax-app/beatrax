<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\DevMode\Internal\Doctor\ProbeOutputParser;

/**
 * `/dev/doctor` panel (CONTEXT D-43).
 *
 * Thin wrapper that triggers `beatrax:doctor` through the SAME
 * Process+SSE pipeline as the 16-04 ArtisanRunnerPage. The page itself
 * does not own the SSE consumer — that lives in Alpine on the page
 * (POST `/dev/artisan/spawn` with `command=beatrax:doctor`, then open
 * an EventSource against `/dev/artisan/stream/{runId}`). When the
 * stream terminates, the captured stdout lands in the
 * `dev_mode_audit` row via the existing FinalizeRunAudit hook (16-04b
 * pipeline) — so on the NEXT GET, this page reads the latest
 * `beatrax:doctor` audit row's `properties.stdout_excerpt` and parses
 * it via {@see ProbeOutputParser} into pass/warn/fail rows.
 *
 * Single code path = identical UX between "run from CLI" and "run
 * from /dev/doctor": both write a `dev_mode_audit` row that the page
 * reads back. The Re-run button surfaces the spawn endpoint; the
 * audit row is the source of truth for what's displayed.
 *
 * Pattern B (PATTERNS.md): no constructor on a Livewire Component;
 * collaborators arrive as method-DI on render().
 */
#[Layout('dev::layouts.dev-shell')]
final class DoctorPanelPage extends Component
{
    public function render(
        ViewFactory $views,
        DatabaseManager $db,
        ProbeOutputParser $parser,
    ): View {
        $latest = $db->connection()->table('dev_mode_audit')
            ->where('log_name', 'dev_mode')
            ->where('properties->command', 'beatrax:doctor')
            ->orderByDesc('created_at')
            ->limit(1)
            ->first();

        $rows = [];
        $stdout = null;
        $finishedAt = null;
        $exitCode = null;
        if ($latest !== null) {
            $vars = get_object_vars($latest);
            $rawProperties = $vars['properties'] ?? null;
            if (is_string($rawProperties)) {
                $decoded = json_decode($rawProperties, true);
                if (is_array($decoded)) {
                    $stdoutValue = $decoded['stdout_excerpt'] ?? null;
                    if (is_string($stdoutValue)) {
                        $stdout = $stdoutValue;
                        $rows = $parser->parse($stdoutValue);
                    }
                    $exitValue = $decoded['exit_code'] ?? null;
                    if (is_int($exitValue)) {
                        $exitCode = $exitValue;
                    }
                }
            }
            $createdAtRaw = $vars['created_at'] ?? null;
            if (is_string($createdAtRaw)) {
                try {
                    $finishedAt = CarbonImmutable::parse($createdAtRaw)->toIso8601String();
                } catch (\Throwable) {
                    $finishedAt = $createdAtRaw;
                }
            }
        }

        return $views->make('dev::livewire.doctor-panel-page', [
            'probeRows' => $rows,
            'rawStdout' => $stdout,
            'finishedAt' => $finishedAt,
            'exitCode' => $exitCode,
        ]);
    }
}
