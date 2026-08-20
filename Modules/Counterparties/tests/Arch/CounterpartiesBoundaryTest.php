<?php

declare(strict_types=1);

// Duplicates a rule from tests/Contracts/BoundaryArchTest.php so that
// `vendor/bin/pest Modules/Counterparties/` alone still fails on a
// cross-module reach; the project-wide test is the authoritative gate.
arch('Modules\\Counterparties\\Internal is only used inside Modules\\Counterparties')
    ->expect('Modules\\Counterparties\\Internal')
    ->toOnlyBeUsedIn('Modules\\Counterparties');
