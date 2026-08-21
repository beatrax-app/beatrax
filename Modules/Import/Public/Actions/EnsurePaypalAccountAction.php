<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;

// Without this synthetic-IBAN account every imported PayPal row is an
// unknown-IBAN error, the statement_summaries writer never fires, and the
// starting-balance detector downstream has nothing to read.
final readonly class EnsurePaypalAccountAction
{
    // Mirrors PreviewWizard::PAYPAL_OWN_IBAN; AccountResolver looks up by
    // (iban, user_id), so the two must agree.
    public const string PAYPAL_OWN_IBAN = 'PAYPAL';

    public function __construct(private DatabaseManager $db) {}

    public function __invoke(
        User $user,
        ?string $nameOverride = null,
        ?string $slugBodyOverride = null,
    ): bool {
        // Raw builder rather than Account::query()->exists(), which trips
        // PHPStan strict-rules staticMethod.dynamicCall.
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
            'kind' => AccountKind::Paypal->value,
            'iban' => self::PAYPAL_OWN_IBAN,
            'default_currency' => 'EUR',
        ]);

        return true;
    }
}
