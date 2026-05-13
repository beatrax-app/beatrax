<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Exceptions\InvalidAccountNameException;
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
 *
 * Name validation lives in the service (not the Livewire layer) so every
 * caller — CLI, programmatic, future REST entrypoint — gets the same
 * 1..80 character bound. Throws InvalidAccountNameException on empty input
 * or input that exceeds 80 multibyte characters; the wizard catches it and
 * surfaces the message next to the input via Livewire's error bag.
 */
final class AccountNamer implements NamesAccounts
{
    public const NAME_MIN_LENGTH = 1;

    public const NAME_MAX_LENGTH = 80;

    public function __invoke(string $iban, string $userSuppliedName, User $user): int
    {
        $trimmed = trim($userSuppliedName);
        $length = mb_strlen($trimmed);

        if ($length < self::NAME_MIN_LENGTH || $length > self::NAME_MAX_LENGTH) {
            throw new InvalidAccountNameException(sprintf(
                'Account name must be %d..%d characters.',
                self::NAME_MIN_LENGTH,
                self::NAME_MAX_LENGTH,
            ));
        }

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
