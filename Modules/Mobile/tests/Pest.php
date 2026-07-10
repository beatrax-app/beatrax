<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mobile\Tests\TestCase;

/*
 * Inert convention file — Pest's BootFiles bootstrapper only auto-loads
 * `tests/Pest.php` at the project root; the root file's `foreach` loop
 * over every module (including 'Modules/Mobile' => Modules\Mobile\Tests\
 * TestCase::class) is what actually binds this module's Feature/Unit
 * suites to RefreshDatabase + TestCase. Kept for the per-module scaffold
 * shape parity with every other module (see tests/Pest.php's own comment).
 */
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
