<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

/**
 * A `migration_staging_unmapped_items` conflict row (`item_type =
 * 'conflict'`), re-hydrated for `ConfirmMigration`'s resolution-apply step
 * (13.5-HUMAN-UAT.md Test 3c gap-fix). Deliberately a plain typed value
 * object rather than a raw `stdClass` query result — `ConfirmMigration`
 * branches on `entityType`/`resolution` several times, and a named class
 * keeps those checks self-documenting under PHPStan level 10 strict.
 */
final class ConflictRow
{
    public function __construct(
        public readonly string $entityType,
        public readonly ?string $sourceExternalId,
        public readonly string $fieldName,
        public readonly ?string $localValue,
        public readonly ?string $sourceValue,
        /** NULL and 'keep_local' are equivalent (D-14 default). */
        public readonly string $resolution,
    ) {}
}
