<?php

declare(strict_types=1);

namespace Modules\FX\Tests;

use Tests\TestCase as RootTestCase;

/**
 * FX module-local TestCase. Extends the root TestCase; module-specific
 * test bootstrap attaches here when later waves introduce factories or fakes.
 */
abstract class TestCase extends RootTestCase {}
