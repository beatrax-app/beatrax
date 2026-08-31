<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Modules\Core\Models\User;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ledger\Models\Account;

// Constructed per import run with the user bound, so an adapter can resolve
// once per source row and the wizard can branch on unknown IBANs before the
// user confirms.
final readonly class EloquentAccountResolver implements AccountResolver
{
    public function __construct(private User $user) {}

    public function resolve(string $iban): AccountResolution
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->where('iban', $iban)
            ->where('user_id', $this->user->id)
            ->first();

        if ($account === null) {
            return AccountResolution::unknown($iban);
        }

        return AccountResolution::known($account->id);
    }
}
