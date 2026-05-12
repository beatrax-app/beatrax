<?php

declare(strict_types=1);

namespace Modules\Ingestion\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Ingestion module-local TestCase. Extends the root TestCase; module-specific
 * test bootstrap (factories, container bindings) attach here in later plans.
 */
abstract class TestCase extends RootTestCase {}
