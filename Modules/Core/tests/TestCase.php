<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use Illuminate\Database\QueryException;
use Modules\Ledger\Models\Currency;
use Tests\TestCase as RootTestCase;

abstract class TestCase extends RootTestCase
{
    // Any form validating a currency against `exists:currencies,code` fails in
    // an unseeded test, so the three the fixtures use are always present. The
    // table only exists once migrations have run, hence the QueryException
    // catch for Unit tests.
    protected function setUp(): void
    {
        parent::setUp();

        try {
            Currency::query()->updateOrInsert(
                ['code' => 'EUR'],
                ['name' => 'Euro', 'minor_unit' => 2],
            );
            Currency::query()->updateOrInsert(
                ['code' => 'USD'],
                ['name' => 'US Dollar', 'minor_unit' => 2],
            );
            Currency::query()->updateOrInsert(
                ['code' => 'GBP'],
                ['name' => 'Pound Sterling', 'minor_unit' => 2],
            );
        } catch (QueryException) {
            // No currencies table (Unit tests without RefreshDatabase).
        }
    }
}
