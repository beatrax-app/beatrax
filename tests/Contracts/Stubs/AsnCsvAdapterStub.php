<?php

declare(strict_types=1);

namespace Tests\Contracts\Stubs;

/**
 * Placeholder reference used by the IdempotencyContractTest dataset.
 *
 * The dataset entries reference adapters by format string (e.g. `'asn-csv'`),
 * not by class — the real Modules\Ingestion\Internal\Adapters\Asn\AsnCsvAdapter
 * is registered against the format string in Plan 04. This stub exists so
 * IDE / tooling references remain stable.
 */
final class AsnCsvAdapterStub
{
    public string $format = 'asn-csv';
}
