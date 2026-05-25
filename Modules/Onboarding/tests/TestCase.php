<?php

declare(strict_types=1);

namespace Modules\Onboarding\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Onboarding module-local TestCase. Extends the root TestCase;
 * module-specific test bootstrap (factories, container bindings) attach
 * here when needed.
 */
abstract class TestCase extends RootTestCase {}
