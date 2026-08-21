<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Internal\Exceptions\InvalidAccountNameException;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\AccountSlugResolver;

/**
 * @link ../../../../.docs/features/import/architecture.md#merchant-aliases
 */
final class AccountNamer implements NamesAccounts
{
    public const NAME_MIN_LENGTH = 1;

    public const NAME_MAX_LENGTH = 80;

    // ISO 13616: 15 is Norway, the shortest national format; 34 is the
    // published global maximum.
    private const IBAN_MIN_LENGTH = 15;

    private const IBAN_MAX_LENGTH = 34;

    public function __construct(private readonly AccountSlugResolver $slugs) {}

    public function __invoke(string $iban, string $userSuppliedName, User $user): int
    {
        $trimmed = self::validateName($userSuppliedName);

        // Structural only. Mod-97 is deliberately not enforced: counterparty
        // IBANs from MT940 and CAMT extracts arrive truncated, and rejecting
        // them would lose legitimate rows.
        $pattern = sprintf(
            '/^[A-Z0-9]{%d,%d}$/',
            self::IBAN_MIN_LENGTH,
            self::IBAN_MAX_LENGTH,
        );
        if (preg_match($pattern, $iban) !== 1) {
            throw new InvalidAccountNameException(Lang::get('import::accounts.errors.iban_format', [
                'min' => self::IBAN_MIN_LENGTH,
                'max' => self::IBAN_MAX_LENGTH,
            ]));
        }

        $account = Account::create([
            'user_id' => $user->id,
            'name' => $trimmed,
            'slug' => $this->slugs->resolveUnique($user->id, $trimmed),
            'kind' => AccountKind::Bank->value,
            'iban' => $iban,
            'default_currency' => 'EUR',
        ]);

        return $account->id;
    }

    // Shared by every naming path so the bound and the slug guard cannot
    // drift apart. The slug itself is AccountSlugResolver's answer, not this
    // one's — all this owes the caller is a name it can build one from.
    public static function validateName(string $userSuppliedName): string
    {
        $trimmed = trim($userSuppliedName);
        $length = mb_strlen($trimmed);

        if ($length < self::NAME_MIN_LENGTH || $length > self::NAME_MAX_LENGTH) {
            throw new InvalidAccountNameException(Lang::get('import::accounts.errors.name_length', [
                'min' => self::NAME_MIN_LENGTH,
                'max' => self::NAME_MAX_LENGTH,
            ]));
        }

        // A name made entirely of characters Str::slug() cannot transliterate
        // — emoji, punctuation, some scripts — clears the length bound and
        // still slugs to nothing.
        if (Str::slug($trimmed) === '') {
            throw new InvalidAccountNameException(
                Lang::get('import::accounts.errors.name_slug')
            );
        }

        return $trimmed;
    }
}
