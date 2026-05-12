<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Modules\Ledger\Models\Account;

/**
 * Root TestCase. Module-local TestCases extend this one.
 *
 * The `$fixtureUser` property and `seedFixtureUserAndAccount()` helper exist
 * here so the cross-module IdempotencyContractTest (tests/Contracts) and the
 * AsnCsvImportTest in Modules/Import/tests/Feature share a single canonical
 * seeded user + ASN account row. The helper resolves against the
 * App\Models\User class alias that Plan 02's CoreServiceProvider registers
 * for Modules\Core\Models\User; on a fresh Plan 01 checkout the User and
 * Account models do not yet exist, so the helper goes RED in Wave 1 with a
 * "Class not found" error until Plan 02 ships the User model and Plan 03
 * ships the Account model + migration. This is the intentional baseline
 * documented in 01-VALIDATION.md.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * The canonical fixture user resolved during seedFixtureUserAndAccount().
     *
     * Typed via the App\Models\User class alias for stability — the alias is
     * registered in Plan 02's CoreServiceProvider so legacy Laravel idioms
     * keep working alongside the Modules\Core\Models\User canonical model.
     */
    protected ?User $fixtureUser = null;

    /**
     * Seeds the canonical fixture User + ASN Account so the contract tests
     * and the Modules\Import\Tests\Feature\AsnCsvImportTest can resolve the
     * fixture's own-IBAN without falling through to the unknown-IBAN wizard
     * step.
     *
     * IBAN `NL00ASNB0123456789` is the load-bearing anonymization-protocol
     * value baked into tests/fixtures/asn-sample-1.csv. Do NOT change this
     * literal — the EloquentAccountResolver shipped by Plan 05 looks it up
     * directly.
     *
     * RED in Wave 1: until Plan 02 ships Modules\Core\Models\User and Plan 03
     * ships Modules\Ledger\Models\Account + its migration, this helper raises
     * "Class not found". The behaviour is intentional and documented in
     * 01-VALIDATION.md.
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
                'currency' => 'EUR',
                'default_currency' => 'EUR',
            ],
        );

        return ['user' => $this->fixtureUser, 'account' => $account];
    }
}
