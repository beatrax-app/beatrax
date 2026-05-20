<?php

declare(strict_types=1);

namespace Modules\Auth\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Auth module-local TestCase. Extends the root TestCase; module-
 * specific test bootstrap (factories, container bindings) attach here
 * when needed.
 */
abstract class TestCase extends RootTestCase {}
