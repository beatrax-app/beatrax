<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Support;

// Open for exactly as long as Migrator::runPending() sits between its own
// start and end events. Schema work emits ordinary INSERT statements, and a
// listener that reads those as user writes reaches for tables the same run
// has not created yet.
final class MigrationWindow
{
    private bool $open = false;

    public function open(): void
    {
        $this->open = true;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function isOpen(): bool
    {
        return $this->open;
    }
}
