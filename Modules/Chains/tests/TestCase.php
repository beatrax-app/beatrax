<?php

declare(strict_types=1);

namespace Modules\Chains\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Chains module-local TestCase. Extends the root TestCase; module-
 * specific test bootstrap (factories, container bindings) attach here
 * when needed.
 */
abstract class TestCase extends RootTestCase {}
