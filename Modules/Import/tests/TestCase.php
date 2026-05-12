<?php

declare(strict_types=1);

namespace Modules\Import\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Import module-local TestCase. Extends the root TestCase; module-specific
 * test bootstrap (factories, container bindings) attach here in later plans.
 */
abstract class TestCase extends RootTestCase {}
