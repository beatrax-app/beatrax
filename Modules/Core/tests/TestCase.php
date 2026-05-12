<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Core module-local TestCase. Extends the root TestCase; module-specific
 * test bootstrap (factories, container bindings) attach here when needed.
 */
abstract class TestCase extends RootTestCase {}
