<?php

declare(strict_types=1);

namespace Modules\Receipts\Tests;

use Tests\TestCase as RootTestCase;

/**
 * Receipts module-local TestCase. Extends the root TestCase; module-
 * specific test bootstrap (factories, container bindings, fake matcher
 * registrations) attach here when needed.
 */
abstract class TestCase extends RootTestCase {}
