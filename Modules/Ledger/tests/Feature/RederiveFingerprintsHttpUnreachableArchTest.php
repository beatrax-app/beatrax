<?php

declare(strict_types=1);

/*
 * Mirror of the BoundaryArchTest rule, grouped into phase-2 so the
 * vendor/bin/pest --group=phase-2 focused run also enforces the
 * HTTP-unreachability boundary on the rederive command. Both rules must
 * pass — this dedicated file ensures the focused dev loop catches a
 * regression without depending on the global Contracts suite.
 */

arch('RederiveFingerprintsCommand is never imported by any HTTP or routing namespace (phase-2 mirror)')
    ->expect('Modules\\Ledger\\Internal\\Console\\RederiveFingerprintsCommand')
    ->not->toBeUsedIn([
        'Modules\\Ledger\\Internal\\Http',
        'Modules\\Ledger\\Public\\Http',
        'Modules\\Ledger\\Routes',
        'Modules\\Core\\Internal\\Http',
        'Modules\\Core\\Public\\Http',
        'Modules\\Ingestion\\Internal\\Http',
        'Modules\\Ingestion\\Public\\Http',
        'Modules\\Import\\Internal\\Http',
        'Modules\\Import\\Public\\Http',
        'Modules\\Categorization\\Internal\\Http',
        'Modules\\Categorization\\Public\\Http',
    ])
    ->group('phase-2');
