<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

final class ComposerVersionProbe extends ExternalToolVersionProbe
{
    public function __construct()
    {
        parent::__construct(
            label: 'Composer',
            argv: ['composer', '--version'],
            missingMessage: 'Composer not on PATH',
        );
    }
}
