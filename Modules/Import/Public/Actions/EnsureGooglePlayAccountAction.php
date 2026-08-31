<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Import\Public\Services\AccountDenomination;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountSlugResolver;

// Google Play issues receipts and no statement export, so this synthetic-IBAN
// account is the only thing a parsed Play receipt can resolve against; without
// it the receipt parses, the audit row says processed, and the ledger is empty.
final readonly class EnsureGooglePlayAccountAction
{
    // AccountResolver looks up by (iban, user_id), so this stays the one
    // sentinel every Google Play receipt is keyed to.
    public const string GOOGLE_PLAY_OWN_IBAN = SyntheticIban::GooglePlay->value;

    private const string DEFAULT_NAME = 'Google Play';

    public function __construct(
        private DatabaseManager $db,
        private AccountSlugResolver $slugs,
        private AccountDenomination $denomination,
    ) {}

    public function __invoke(
        User $user,
        ?string $nameOverride = null,
        ?string $statementCurrency = null,
    ): bool {
        // Account::query()->exists() trips the strict-rules staticMethod.dynamicCall
        // check, so the existence probe goes through the raw query builder.
        $exists = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where('iban', self::GOOGLE_PLAY_OWN_IBAN)
            ->exists();

        if ($exists) {
            return false;
        }

        $name = ($nameOverride !== null && $nameOverride !== '') ? $nameOverride : self::DEFAULT_NAME;

        Account::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'slug' => $this->slugs->resolveUnique($user->id, $name),
            'kind' => AccountKind::GooglePlay->value,
            'iban' => self::GOOGLE_PLAY_OWN_IBAN,
            'default_currency' => $this->denomination->forStatement($statementCurrency),
        ]);

        return true;
    }
}
