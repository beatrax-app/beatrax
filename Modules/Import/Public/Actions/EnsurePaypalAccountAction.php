<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountSlugResolver;
use Modules\Ledger\Public\Services\BaseCurrency;

// Without this synthetic-IBAN account every imported PayPal row is an
// unknown-IBAN error, the statement_summaries writer never fires, and the
// starting-balance detector downstream has nothing to read.
final readonly class EnsurePaypalAccountAction
{
    // Mirrors PreviewWizard::PAYPAL_OWN_IBAN; AccountResolver looks up by
    // (iban, user_id), so the two must agree.
    public const string PAYPAL_OWN_IBAN = 'PAYPAL';

    public function __construct(
        private DatabaseManager $db,
        private AccountSlugResolver $slugs,
        private BaseCurrency $baseCurrency,
    ) {}

    public function __invoke(
        User $user,
        ?string $nameOverride = null,
    ): bool {
        // Account::query()->exists() trips the strict-rules staticMethod.dynamicCall
        // check, so the existence probe goes through the raw query builder.
        $exists = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', self::PAYPAL_OWN_IBAN)
            ->exists();

        if ($exists) {
            return false;
        }

        $name = ($nameOverride !== null && $nameOverride !== '') ? $nameOverride : 'PayPal';

        Account::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'slug' => $this->slugs->resolveUnique($user->id, $name),
            'kind' => AccountKind::Paypal->value,
            'iban' => self::PAYPAL_OWN_IBAN,
            'default_currency' => $this->baseCurrency->code(),
        ]);

        return true;
    }
}
