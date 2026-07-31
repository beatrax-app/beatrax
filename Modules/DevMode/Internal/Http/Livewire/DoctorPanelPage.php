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

        $snapshot = $latest === null
            ? ['probeRows' => [], 'rawStdout' => null, 'finishedAt' => null, 'exitCode' => null]
            : $this->snapshotFromRow($latest, $parser);

        return $views->make('dev::livewire.doctor-panel-page', $snapshot);
    }

    /**
     * @return array{probeRows: list<array<string, mixed>>, rawStdout: ?string, finishedAt: ?string, exitCode: ?int}
     */
    private function snapshotFromRow(object $latest, ProbeOutputParser $parser): array
    {
        $vars = get_object_vars($latest);
        $properties = $this->decodeProperties($vars['properties'] ?? null);

        $stdout = is_string($properties['stdout_excerpt'] ?? null) ? $properties['stdout_excerpt'] : null;
        $exitValue = $properties['exit_code'] ?? null;

        return [
            'probeRows' => $stdout !== null ? $parser->parse($stdout) : [],
            'rawStdout' => $stdout,
            'finishedAt' => $this->parseFinishedAt($vars['created_at'] ?? null),
            'exitCode' => is_int($exitValue) ? $exitValue : null,
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeProperties(mixed $rawProperties): array
    {
        if (! is_string($rawProperties)) {
            return [];
        }
        $decoded = json_decode($rawProperties, true);

        return is_array($decoded) ? $decoded : [];
    }

    // Falls back to the raw created_at string when Carbon cannot parse
    // it, so a malformed timestamp still renders something rather than
    // blanking the "last run" line.
    private function parseFinishedAt(mixed $createdAtRaw): ?string
    {
        if (! is_string($createdAtRaw)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($createdAtRaw)->toIso8601String();
        } catch (\Throwable) {
            return $createdAtRaw;
        }
    }
}
