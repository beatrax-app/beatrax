<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The other half of MessageNamesNoUserData. That marker says a message may be
// logged whole; this one is for a refusal whose message may not, because it
// quotes the cell. It hands the log the three fields separately instead, so a
// diagnostic composed around a cell is not lost between the parser and the log.
interface NamesTheCellItRefused
{
    public function refusedCell(): ?RefusedCell;
}
