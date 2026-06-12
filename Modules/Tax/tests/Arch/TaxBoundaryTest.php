<?php

declare(strict_types=1);

/*
 * Module-local boundary arch invariant for the Tax module.
 *
 * This file is the per-module satellite of the project-wide
 * `tests/Contracts/BoundaryArchTest.php` rule for `Modules\Tax\Internal`.
 * The two assertions live side by side so a developer running only the
 * module's test subtree (`vendor/bin/pest Modules/Tax/`) still fails fast
 * on a cross-module reach into the module's Internal namespace; the
 * project-wide BoundaryArchTest is the authoritative gate that runs as
 * part of `composer test`.
 *
 * Pest's `arch()` plugin walks the class graph of every namespace the
 * autoloader knows about, so the rule binds correctly even though the
 * test file lives inside the module subtree rather than alongside the
 * other project-wide arch invariants.
 */
arch('Modules\\Tax\\Internal is only used inside Modules\\Tax')
    ->expect('Modules\\Tax\\Internal')
    ->toOnlyBeUsedIn('Modules\\Tax');
