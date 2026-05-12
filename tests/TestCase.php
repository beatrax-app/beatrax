<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Modules\Ledger\Models\Account;

/**
 * Root TestCase. Module-local TestCases extend this one.
 *
 * `$fixtureUser` and `seedFixtureUserAndAccount()` exist here so the
 * cross-module IdempotencyContractTest and the AsnCsvImportTest in the
 * Import module share a single canonical seeded user + ASN account row.
 * The helper resolves against the `App\Models\User` class alias that
 * `CoreServiceProvider` registers for `Modules\Core\Models\User`.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * The canonical fixture user resolved during seedFixtureUserAndAccount().
     *
     * Typed via the App\Models\User class alias for stability — the alias is
     * registered in CoreServiceProvider so legacy Laravel idioms keep working
     * alongside the Modules\Core\Models\User canonical model.
     */
    protected ?User $fixtureUser = null;

    /**
     * Seeds the canonical fixture User + ASN Account so the contract tests
     * and Modules\Import\Tests\Feature\AsnCsvImportTest can resolve the
     * fixture's own-IBAN without falling through to the unknown-IBAN
     * wizard step.
     *
     * IBAN `NL00ASNB0123456789` is the load-bearing anonymisation value
     * baked into tests/fixtures/asn-sample-1.csv. Do NOT change this
     * literal — `EloquentAccountResolver` looks it up directly.
     *
     * @return array{user: User, account: Account}
     */
    public function seedFixtureUserAndAccount(): array
    {
        $this->fixtureUser = User::query()->updateOrCreate(
            ['email' => 'fixture@diederik.test'],
            ['password' => 'fixture-password', 'period_start_day' => 1],
        );

        $account = Account::query()->updateOrCreate(
            ['iban' => 'NL00ASNB0123456789'],
            [
                'user_id' => $this->fixtureUser->id,
                'name' => 'ASN Fixture Account',
                'slug' => 'asn-fixture',
                'kind' => 'asn',
                'default_currency' => 'EUR',
            ],
        );

        return ['user' => $this->fixtureUser, 'account' => $account];
    }
}
