<?php

declare(strict_types=1);

namespace Modules\Shell\Tests;

use Illuminate\Database\QueryException;
use Modules\Ledger\Models\Currency;
use Tests\TestCase as RootTestCase;

abstract class TestCase extends RootTestCase
{
    // SettingsPage validates baseCurrency against `exists:currencies,code`, so
    // without EUR every save() in an unseeded test fails validation. The table
    // only exists once migrations have run, hence the QueryException catch.
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
