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
 * @link ../../../../../.docs/features/dev-mode/architecture.md
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
