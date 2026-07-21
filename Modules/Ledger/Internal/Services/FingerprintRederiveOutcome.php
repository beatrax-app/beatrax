<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Services;

// The status surface is small on purpose: callers only need to
// distinguish "all good" from "stop, do not write", plus the
// rows-affected count or collision detail for reporting.
final class FingerprintRederiveOutcome
{
    /**
     * @param  list<array{existing_id:int,colliding_id:int,fingerprint:string}>  $collisions
     */
    private function __construct(
        public readonly string $status,
        public readonly int $targetVersion,
        public readonly int $rowsAffected,
        public readonly array $collisions,
    ) {}

    public static function noop(int $targetVersion): self
    {
        return new self('noop', $targetVersion, 0, []);
    }

    public static function dryRun(int $targetVersion, int $pendingCount): self
    {
        return new self('dry_run', $targetVersion, $pendingCount, []);
    }

    public static function applied(int $targetVersion, int $rowsAffected): self
    {
        return new self('applied', $targetVersion, $rowsAffected, []);
    }

    /**
     * @param  list<array{existing_id:int,colliding_id:int,fingerprint:string}>  $collisions
     */
    public static function collided(int $targetVersion, array $collisions): self
    {
        return new self('collided', $targetVersion, 0, $collisions);
    }

    public function isCollision(): bool
    {
        return $this->status === 'collided';
    }
}
