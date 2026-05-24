<?php

declare(strict_types=1);

/*
 * Dev Console routes. Mounted by DevModeServiceProvider via
 * `loadRoutesFrom(__DIR__.'/../Routes/web.php')`.
 *
 * Task 2 of plan 16-03 fills this file with the `/dev` route group
 * that mounts the DevOverviewPage Livewire component behind the
 * `web` + `auth` + `ensureDeveloperMode` middleware stack. Downstream
 * Wave 4 / Wave 5 / Wave 6 / Wave 7 plans (16-04 through 16-07)
 * append routes inside the same `->group(...)` (artisan runner, audit,
 * logs, queue, doctor, sql, horizon, system).
 *
 * Every `/dev/*` route MUST apply `ensureDeveloperMode`. The arch
 * invariant `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware`
 * (in tests/Contracts/BoundaryArchTest.php) locks coverage at PR time.
 */
