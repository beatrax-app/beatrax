<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EmailScan\Tests\TestCase;

// Inert on purpose: Pest's BootFiles bootstrapper only auto-loads the
// project-root tests/Pest.php, which is where every module's directories are
// actually bound. This file exists to keep the convention discoverable.

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Contracts');

pest()->extend(TestCase::class)->in('Unit');
