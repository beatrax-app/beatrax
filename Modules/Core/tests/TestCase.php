<?php

declare(strict_types=1);

namespace Modules\Core\Tests;

use Illuminate\Database\QueryException;
use Modules\Ledger\Models\Currency;
use Tests\TestCase as RootTestCase;

/**
 * Core module-local TestCase. Extends the root TestCase; module-specific
 * test bootstrap (factories, container bindings) attach here when needed.
 */
abstract class TestCase extends RootTestCase
{
    /**
     * Seed the global ISO-4217 currency reference rows that Core feature
     * tests require. The SettingsPage validates `baseCurrency` against
     * `exists:currencies,code`; without at least EUR present, every
     * `save()` call in a test that has not explicitly seeded currencies
     * fails validation. Currencies are a global reference table (not
     * per-user data) and are always present in production — this setUp()
     * mirrors that invariant for the test harness.
     *
     * The currencies table only exists when migrations have run (Feature
     * tests with RefreshDatabase). Unit tests skip this seeding — the
     * try-catch around the QueryException avoids a "no such table" crash in
     * non-migrated test harnesses.
     */
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
            // currencies table does not exist (Unit tests without RefreshDatabase) — skip.
        }
    }
}
