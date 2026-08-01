<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Exceptions\InvalidAccountNameException;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;

/**
 * @link ../../../../.docs/features/import/architecture.md#merchant-aliases
 */
final class AccountNamer implements NamesAccounts
{
    public const NAME_MIN_LENGTH = 1;

    public const NAME_MAX_LENGTH = 80;

    // Per ISO 13616: 15-char floor admits the shortest national format
    // (Norway); 34-char ceiling is the published global maximum.
    private const IBAN_MIN_LENGTH = 15;

    private const IBAN_MAX_LENGTH = 34;

    public function __invoke(string $iban, string $userSuppliedName, User $user): int
    {
        [$trimmed, $slugBody] = self::validateName($userSuppliedName);

        // Structural IBAN guard only — Mod-97 checksum validation is
        // intentionally not enforced since counterparty IBANs from
        // MT940/CAMT extracts can already be truncated. Catches the
        // common-case corruption without rejecting legitimate edge cases.
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

        $tail = substr($iban, -8);

        $account = Account::create([
            'user_id' => $user->id,
            'name' => $trimmed,
            'slug' => $slugBody.'-'.strtolower($tail),
            'kind' => AccountKind::Bank->value,
            'iban' => $iban,
            'default_currency' => 'EUR',
        ]);

        return $account->id;
    }

    // Shared by the IBAN-naming and synthetic-IBAN-naming paths so the
    // 1..80 character bound and slug-body guard stay in lock step.
    /**
     * @return array{0: string, 1: string}
     */
    public static function validateName(string $userSuppliedName): array
    {
        $trimmed = trim($userSuppliedName);
        $length = mb_strlen($trimmed);

        if ($length < self::NAME_MIN_LENGTH || $length > self::NAME_MAX_LENGTH) {
            throw new InvalidAccountNameException(Lang::get('import::accounts.errors.name_length', [
                'min' => self::NAME_MIN_LENGTH,
                'max' => self::NAME_MAX_LENGTH,
            ]));
        }

        // Str::slug() strips characters it cannot transliterate (emoji,
        // punctuation, untransliterable scripts). A name composed
        // entirely of such characters passes the length bound but
        // produces an empty slug — reject so every account gets a slug.
        $slugBody = Str::slug($trimmed);
        if ($slugBody === '') {
            throw new InvalidAccountNameException(
                Lang::get('import::accounts.errors.name_slug')
            );
        }

        return [$trimmed, $slugBody];
    }
}
