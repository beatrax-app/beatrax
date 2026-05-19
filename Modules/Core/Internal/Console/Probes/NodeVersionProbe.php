<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

final class NodeVersionProbe extends ExternalToolVersionProbe
{
    public function __construct()
    {
        parent::__construct(
            label: 'Node',
            argv: ['node', '--version'],
            missingMessage: 'node not on PATH (required for Vite asset builds)',
        );
    }
}
