<?php

declare(strict_types=1);

namespace Modules\Position\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Position module-local TestCase. Extends the root TestCase;
 * module-specific test bootstrap attaches here when a later wave
 * introduces factories, container bindings, or fake services.
 */
abstract class TestCase extends RootTestCase {}
