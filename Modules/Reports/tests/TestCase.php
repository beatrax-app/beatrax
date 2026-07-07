<?php

declare(strict_types=1);

namespace Modules\Reports\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Reports module-local TestCase. Extends the root TestCase; module-specific
 * test bootstrap attaches here when later waves introduce factories or fakes.
 */
abstract class TestCase extends RootTestCase {}
