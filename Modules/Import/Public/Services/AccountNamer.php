<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Ledger\Models\Account;

/**
 * Creates an Account row for an unknown IBAN. The wizard invokes this when
 * the user supplies a name during the inline account-naming step. Stamps
 * `user_id` from the supplied user (the request's CurrentUser) so newly
 * named accounts can never leak across users.
 *
 * Slug derivation: `slug($name) + '-' + last8(iban)`. The last 8 characters
 * cover both BBAN groups (Dutch IBANs put the 4-digit bank check digit at
 * the start; the discriminating bytes are at the tail). Using 8 instead of
 * 4 dramatically lowers the chance of two distinct IBANs producing the same
 * slug, and the per-user UNIQUE on `(user_id, slug)` plus the per-user
 * UNIQUE on `(user_id, iban)` guarantee the same IBAN never lands twice.
 */
final class AccountNamer implements NamesAccounts
{
    public function __invoke(string $iban, string $userSuppliedName, User $user): int
    {
        $trimmed = trim($userSuppliedName);
        $tail = substr($iban, -8);

        $account = Account::create([
            'user_id' => $user->id,
            'name' => $trimmed,
            'slug' => Str::slug($trimmed).'-'.strtolower($tail),
            'kind' => 'asn',
            'iban' => $iban,
            'default_currency' => 'EUR',
        ]);

        return $account->id;
    }
}
