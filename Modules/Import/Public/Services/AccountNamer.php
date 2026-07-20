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
 * Validation lives in the service (not the Livewire layer) so every
 * caller — CLI, programmatic, future REST entrypoint — gets the same
 * 1..80 character bound on the name plus the structural IBAN guard
 * (uppercase alphanumeric, 15..34 characters per ISO 13616). The IBAN
 * shape check is defence-in-depth against malformed values landing in
 * `accounts.iban`, where future merchant joins and SEPA-export features
 * would later trip over them.
 *
 * Also rejects names whose slug derivation yields an empty body
 * (emoji-only, punctuation-only, or scripts without a transliteration
 * map) so every account row carries a meaningful slug. Throws
 * InvalidAccountNameException in all failure modes; the wizard catches
 * it and surfaces the message next to the input via Livewire's error
 * bag.
 */
final class AccountNamer implements NamesAccounts
{
    public const NAME_MIN_LENGTH = 1;

    public const NAME_MAX_LENGTH = 80;

    /**
     * Minimum and maximum IBAN length per ISO 13616. The 15-character
     * floor admits the shortest national format (Norway, 15 chars);
     * the 34-character ceiling is the published global maximum.
     */
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
            throw new InvalidAccountNameException(sprintf(
                'IBAN must be %d..%d uppercase alphanumeric characters.',
                self::IBAN_MIN_LENGTH,
                self::IBAN_MAX_LENGTH,
            ));
        }

        $tail = substr($iban, -8);

        $account = Account::create([
            'user_id' => $user->id,
            'name' => $trimmed,
            'slug' => $slugBody.'-'.strtolower($tail),
            'kind' => 'asn',
            'iban' => $iban,
            'default_currency' => 'EUR',
        ]);

        return $account->id;
    }

    /**
     * Validates a user-supplied account name and returns the
     * `[trimmedName, slugBody]` pair. Shared by both the IBAN-naming
     * path (the wizard's ASN flow) and the synthetic-IBAN naming path
     * (the wizard's ICS-card flow), so the 1..80 character bound and
     * the slug-body guard stay in lock step across both call sites.
     *
     * Throws `InvalidAccountNameException` with the same user-facing
     * messages the IBAN path emits.
     *
     * @return array{0: string, 1: string}
     */
    public static function validateName(string $userSuppliedName): array
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

        // Str::slug() strips characters it cannot transliterate (emoji,
        // punctuation, untransliterable scripts). A name composed
        // entirely of such characters passes the length bound but
        // produces an empty slug — reject so every account gets a slug.
        $slugBody = Str::slug($trimmed);
        if ($slugBody === '') {
            throw new InvalidAccountNameException(
                'Account name must contain at least one letter or digit.'
            );
        }

        return [$trimmed, $slugBody];
    }
}
