<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Support;

use Modules\Core\Public\Support\Lang;
use Modules\Counterparties\Internal\Enums\CounterpartyMetadataKey;

// `counterparties.display_name` does two jobs: it holds the reader's own
// wording for a row they named, and the app's own English for the few rows the
// resolver had to name itself. `metadata.default_name` says which, and this is
// the one place that asks.
/**
 * @link ../../../../.docs/features/counterparties/resolution-chain.md#the-apps-own-words-for-a-row-it-had-to-name
 */
final class CounterpartyDefaultName
{
    public const string UNKNOWN = 'unknown';

    public const string GOVERNMENT = 'government';

    public const string BANK_FEE = 'bank_fee';

    // Two of the three words are a type's own name, so they read the line the
    // type chip already carries in all 26 locales rather than a second copy of
    // it. A fee is a subcategory rather than a type and has no such line.
    private const array KEYS = [
        self::UNKNOWN => 'counterparties::components.type_chip.unknown',
        self::GOVERNMENT => 'counterparties::components.type_chip.government',
        self::BANK_FEE => 'counterparties::components.default_name.bank_fee',
    ];

    // The English that goes in the column. Storing it rather than a key keeps
    // the slug derivable from the name, keeps the row legible to a reader that
    // has not been through this seam, and is the fallback below.
    private const array STORED = [
        self::UNKNOWN => 'Unknown',
        self::GOVERNMENT => 'Government',
        self::BANK_FEE => 'Bank fee',
    ];

    public static function storedName(string $token): string
    {
        return self::STORED[$token] ?? $token;
    }

    // A row the reader named keeps their words in every language. A row the
    // app named re-resolves for whoever is reading. A token with no line
    // behind it keeps the stored English, which is what was written anyway.
    public static function resolve(string $storedName, mixed $metadata): string
    {
        $key = self::KEYS[self::tokenIn($metadata) ?? ''] ?? null;
        if ($key === null) {
            return $storedName;
        }

        $translated = Lang::get($key);

        return $translated === $key ? $storedName : $translated;
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

        return is_string($token) && isset(self::STORED[$token]) ? $token : null;
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
