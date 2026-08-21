<?php

declare(strict_types=1);

// Mirrors the BoundaryArchTest rule into this group, so the focused dev run
// catches an HTTP import of the command without the whole Contracts suite.

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
