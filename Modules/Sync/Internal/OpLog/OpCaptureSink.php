<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

// What SyncCaptureListener writes through. OpLogWriter signs immediately; the
// others record the coordinate for later or drop it because this device does
// not sync at all. A handler cannot tell them apart, which is what stops one
// capture path being taught to defer and eleven being left behind.
/**
 * @link ../../../../.docs/features/sync/a-mutation-a-keyless-process-cannot-sign.md
 */
interface OpCaptureSink
{
    public function writeSet(string $table, int|string $pk, string $field, mixed $value): void;

    /**
     * @param  int  $delta  How far this device's own count moves. Must be positive.
     */
    public function writeIncrement(string $table, int|string $pk, string $field, int $delta): void;

    /**
     * @param  array<string, mixed>  $fields
     */
    public function writeCreateRow(string $table, int|string $pk, array $fields): void;

    public function writeDelete(string $table, int|string $pk): void;
}
