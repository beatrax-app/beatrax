<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Contracts;

// An adapter that has to read its file whole before it can yield anything drops
// an unreadable row before the pipeline has seen a single one, so nothing that
// counts rows can count it. These are the places in the sequence those rows
// held, and Import fills each one with the row that says it could not be read.
interface NamesRowsItCouldNotRead
{
    /**
     * @return list<int>
     */
    public function unreadableRowIndexes(): array;
}
