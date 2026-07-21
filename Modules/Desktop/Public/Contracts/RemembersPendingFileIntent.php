<?php

declare(strict_types=1);

namespace Modules\Desktop\Public\Contracts;

// The concrete PendingFileIntent is Internal to Desktop; the
// Receipts/Import listeners persist a validated file-open path through
// this contract instead of reaching into Desktop\Internal directly.
interface RemembersPendingFileIntent
{
    /**
     * @param  string  $path  Canonicalised, allow-listed file path.
     * @param  string  $extension  'csv' | 'eml' — without the leading dot.
     */
    public function remember(string $path, string $extension): void;
}
