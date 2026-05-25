<?php

declare(strict_types=1);

namespace Modules\Community\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Community module-local TestCase. Extends the root TestCase;
 * module-specific test bootstrap (factories, container bindings) attach
 * here when needed.
 */
abstract class TestCase extends RootTestCase {}
