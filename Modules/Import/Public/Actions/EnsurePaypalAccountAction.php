<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

// Without this synthetic-IBAN account, every imported PayPal row would
// be an unknown-IBAN error and the statement_summaries writer would
// never fire, blocking the starting-balance detector downstream.
// Returns true on INSERT, false when the account already existed.
final readonly class EnsurePaypalAccountAction
{
    // Mirrors PreviewWizard::PAYPAL_OWN_IBAN — both call sites must use
    // the same literal so AccountResolver lookups by (iban, user_id)
    // resolve consistently.
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
