<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Mobile\Tests\TestCase;

// Inert: Pest's BootFiles bootstrapper only auto-loads tests/Pest.php at the
// project root, and that file's loop over every module is what actually binds
// these suites to RefreshDatabase and TestCase. Kept for scaffold parity.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
