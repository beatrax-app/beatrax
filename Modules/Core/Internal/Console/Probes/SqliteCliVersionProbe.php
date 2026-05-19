<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

final class SqliteCliVersionProbe extends ExternalToolVersionProbe
{
    public function __construct()
    {
        parent::__construct(
            label: 'SQLite',
            argv: ['sqlite3', '--version'],
            missingMessage: 'sqlite3 CLI not on PATH',
        );
    }
}
