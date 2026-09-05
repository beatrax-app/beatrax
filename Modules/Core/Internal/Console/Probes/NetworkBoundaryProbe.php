<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Modules\Core\Internal\Support\NetworkBoundary;

// The health endpoint carries the state word and nothing more, because once the
// boundary is open that body crosses the network. The detail an operator needs
// to act on — which interfaces were taken, which were refused, whether APP_URL
// agrees — belongs here, where the caller already holds a shell on the machine.
final readonly class NetworkBoundaryProbe implements Probe
{
    public function __construct(
        private NetworkBoundary $boundary,
    ) {}

    public function label(): string
    {
        return 'Network boundary';
    }

    public function run(): ProbeResult
    {
        $served = $this->boundary->servedInterfaces();
        $refused = $this->boundary->refusedInterfaces();

        $metadata = [
            'state' => $this->boundary->state()->value,
            'served_interfaces' => implode(' ', $served),
            'refused_interfaces' => implode(' ', $refused),
        ];

        if ($refused !== []) {
            return new ProbeResult(ProbeSeverity::Warning->value, sprintf(
                'Ignored %d configured entr%s naming no single address: %s. Only literal IP addresses widen the boundary.',
                count($refused),
                count($refused) === 1 ? 'y' : 'ies',
                implode(', ', $refused),
            ), $metadata);
        }

        if (! $this->boundary->isWidened()) {
            return new ProbeResult(
                ProbeSeverity::Ok->value,
                'Loopback only. Every non-loopback request is refused with not-found.',
                $metadata,
            );
        }

        return $this->widened($served, $metadata);
    }

    // Widening is a decision, not a fault, so it reads `ok` and names where the
    // boundary is open. The one genuine fault here is a widening APP_URL
    // contradicts, which leaves a bind-address-less runtime refusing everything
    // remote while the interface record says otherwise.
    /**
     * @param  list<string>  $served
     * @param  array<string, scalar|null>  $metadata
     */
    private function widened(array $served, array $metadata): ProbeResult
    {
        $authority = $this->boundary->remoteHostAuthority();

        if ($authority === null) {
            return new ProbeResult(ProbeSeverity::Warning->value, sprintf(
                'Widened to %s, but APP_URL names no host past loopback, so a runtime publishing no bind address still refuses every remote request.',
                implode(', ', $served),
            ), $metadata);
        }

        return new ProbeResult(ProbeSeverity::Ok->value, sprintf(
            'Widened: served on %s, and under host %s where the runtime publishes no bind address. Everything else is refused.',
            implode(', ', $served),
            $authority,
        ), $metadata);
    }
}
