<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Tests\TestCase;

// Inert: Pest's BootFiles bootstrapper auto-loads only the project-root
// tests/Pest.php, which binds every module's directories. This keeps the
// convention visible.

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Contracts');

pest()->extend(TestCase::class)->in('Unit');
