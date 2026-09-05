<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Support;

use Modules\Core\Public\Support\SeededDisplayName;
use Modules\Counterparties\Internal\Enums\CounterpartyMetadataKey;

// `counterparties.display_name` holds the reader's own wording for a row they
// named, and wording nobody chose for them for a row they did not — the app's
// English, or the corpus's own word for a fee. `metadata.default_name` says
// which, and this is the one place that asks.
/**
 * @link ../../../../.docs/features/counterparties/resolution-chain.md#the-apps-own-words-for-a-row-it-had-to-name
 */
final class CounterpartyDefaultName
{
    public const string UNKNOWN = 'unknown';

    public const string GOVERNMENT = 'government';

    public const string BANK_FEE = 'bank_fee';

    // Two of the words are a type's own name, so they read the line the type
    // chip already carries in all 26 locales rather than a second copy of it.
    // A fee is a subcategory rather than a type and has no such line.
    private const array GROUPS = [
        self::UNKNOWN => 'counterparties::components.type_chip.',
        self::GOVERNMENT => 'counterparties::components.type_chip.',
    ];

    private const string FEE_GROUP = 'counterparties::components.default_name.';

    // The kinds of charge the bank-fee corpus sorts a fee word into, listed
    // here rather than read back from the lang group so that a corpus key
    // which is not a kind fails a guard instead of quietly leaving the row in
    // the jurisdiction's wording.
    /** @var list<string> */
    public const array FEE_KINDS = [
        self::BANK_FEE,
        'account_maintenance',
        'monthly_fee',
        'quarterly_fee',
        'annual_fee',
        'card_fee',
        'transaction_fee',
        'transfer_fee',
        'withdrawal_fee',
        'transaction_levy',
        'foreign_transaction_fee',
        'commission',
        'debit_interest',
        'overdraft',
        'overdraft_interest',
        'insufficient_funds',
        'penalty_fee',
        'loan_arrangement_fee',
    ];

    // The English that goes in the column for the three rows the resolver has
    // to name itself. Storing it rather than a key keeps the slug derivable
    // from the name and the row legible to a reader that has not been through
    // this seam. A corpus fee row stores the corpus's own wording instead.
    private const array STORED = [
        self::UNKNOWN => 'Unknown',
        self::GOVERNMENT => 'Government',
        self::BANK_FEE => 'Bank fee',
    ];

    public static function storedName(string $token): string
    {
        return self::STORED[$token] ?? $token;
    }

    // A row the reader named keeps their words in every language; a row the
    // app named re-resolves for whoever is reading. The token's presence is
    // the provenance SeededDisplayName reads off a `name_is_default` column
    // elsewhere, and LabelCounterparty drops it on a rename.
    public static function resolve(string $storedName, mixed $metadata): string
    {
        $token = self::tokenIn($metadata);
        if ($token === null) {
            return $storedName;
        }

        return SeededDisplayName::fromLang(self::groupFor($token), $token, $storedName) ?? $storedName;
    }

    private static function groupFor(string $token): string
    {
        return self::GROUPS[$token] ?? self::FEE_GROUP;
    }

    // The column reaches read sites as a decoded array through the Eloquent
    // cast and as the raw JSON string through the query builder, and both
    // shapes are live on the paths that render a counterparty name.
    public static function tokenIn(mixed $metadata): ?string
    {
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }

        if (! is_array($metadata)) {
            return null;
        }

        $token = $metadata[self::metadataKey()] ?? null;

        return is_string($token) && self::isKnown($token) ? $token : null;
    }

    // A fee kind is as much this column's vocabulary as the three the resolver
    // invents by name, and a token outside both is a corpus typo or an older
    // build's word — either way it must resolve to nothing rather than to a key.
    private static function isKnown(string $token): bool
    {
        return isset(self::STORED[$token]) || in_array($token, self::FEE_KINDS, true);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function mark(array $metadata, ?string $token): array
    {
        if ($token === null) {
            return $metadata;
        }

        $metadata[self::metadataKey()] = $token;

        return $metadata;
    }

    // One spelling, and it is the enum's, beside this table's other two
    // read-back flags. A key spelled twice is a key that drifts, which is what
    // CounterpartyMetadataKey exists to stop.
    private static function metadataKey(): string
    {
        return CounterpartyMetadataKey::DefaultName->value;
    }

    // Provenance travels with the name, and the resolver's refresh pass leaves
    // display_name alone, so a later pass that knows nothing about the name
    // must not be the thing that drops the flag saying whose words it is.
    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function carriedOver(array $stored, array $incoming): array
    {
        return $incoming === [] ? $incoming : self::mark($incoming, self::tokenIn($stored));
    }
}
