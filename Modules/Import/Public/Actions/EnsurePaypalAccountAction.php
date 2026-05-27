<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

/**
 * Ensures the user has a synthetic PayPal `accounts` row keyed on
 * iban `'PAYPAL'`, kind `'paypal'`, EUR-settled. Returns true when
 * an INSERT happened, false when the account already existed.
 *
 * The synthetic-IBAN account is what the PaypalCsvAdapter resolves
 * every imported PayPal row against; without it every preview row
 * would be an unknown-IBAN error and the statement_summaries writer
 * would never fire — which blocks the starting-balance detector
 * downstream.
 *
 * Caller may supply `$nameOverride` and `$slugBodyOverride` to honor
 * a user-typed account name (used by PreviewWizard's name-your-account
 * flow). When both are null the action defaults to name `'PayPal'` and
 * slug `'paypal-paypal'`, which is the shape the wizard's connector
 * step relies on for its non-prompting auto-create path.
 *
 * The existence check is user-scoped via the raw Query Builder so a
 * caller cannot accidentally create a row for the wrong user via
 * session bleed, and so two users each get exactly one PayPal row.
 */
final readonly class EnsurePaypalAccountAction
{
    /**
     * Synthetic own-IBAN literal used for every PayPal account. Mirrors
     * `Modules\Import\Internal\Http\Livewire\PreviewWizard::PAYPAL_OWN_IBAN`
     * — both call sites must use the same literal so AccountResolver
     * lookups by `(iban, user_id)` resolve consistently.
     */
    public const string PAYPAL_OWN_IBAN = 'PAYPAL';

    public function __construct(private DatabaseManager $db) {}

    public function __invoke(
        User $user,
        ?string $nameOverride = null,
        ?string $slugBodyOverride = null,
    ): bool {
        // Raw Query Builder used instead of Account::query()->exists() to
        // satisfy PHPStan strict-rules staticMethod.dynamicCall — same
        // pattern as TransactionDetail and UpdateTransactionCategory.
        $exists = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', self::PAYPAL_OWN_IBAN)
            ->exists();

        if ($exists) {
            return false;
        }

        $name = ($nameOverride !== null && $nameOverride !== '') ? $nameOverride : 'PayPal';
        $slug = ($slugBodyOverride !== null && $slugBodyOverride !== '')
            ? $slugBodyOverride.'-paypal'
            : 'paypal-paypal';

        Account::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'slug' => $slug,
            'kind' => 'paypal',
            'iban' => self::PAYPAL_OWN_IBAN,
            'default_currency' => 'EUR',
        ]);

        return true;
    }
}
