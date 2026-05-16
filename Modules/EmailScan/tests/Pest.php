<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EmailScan\Tests\TestCase;

/*
 * Per-module Pest.php is intentionally inert. Pest's BootFiles
 * bootstrapper only auto-loads the project-root tests/Pest.php, which
 * carries the load-bearing foreach map that binds every module's
 * Feature / Unit / Integration / Contracts directories to the right
 * TestCase + RefreshDatabase. Keeping this file present (mirroring
 * Modules/Chains/tests/Pest.php) preserves the discoverability
 * convention without introducing a second wire-up path.
 */

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Contracts');

pest()->extend(TestCase::class)->in('Unit');
