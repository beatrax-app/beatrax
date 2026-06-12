<?php

declare(strict_types=1);

namespace Modules\Calendar\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Calendar module-local TestCase. Extends the root TestCase;
 * module-specific test bootstrap attaches here when later waves
 * introduce factories, container bindings, or fake services.
 */
abstract class TestCase extends RootTestCase {}
