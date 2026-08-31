<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\ValueObjects\MoneyInput;
use Modules\Search\Public\Dto\SearchFilters;

// What the typist wrote as `account:`, `category:` and `amount:` resolved into
// the filter values a chip or a URL supplies, so both reach the query already
// reconciled. Its own collaborator because resolving a typed name to an id is
// a read of its own, against tables the search itself never touches.
final readonly class SearchTokenFilters
{
    use CoercesScalars;

    // An id no row can hold, standing in for "this token matched nothing".
    // Dropping an unresolvable filter instead returned the WHOLE history,
    // which reads to the typist exactly as if the token had worked.
    private const int NO_SUCH_ID = 0;

    public function __construct(private DatabaseManager $db) {}

    // Merges parsed token filters into the SearchFilters DTO; token
    // filters take precedence. The palette advertises
    // account:/category:/amount: tokens, so they must actually apply.
    /**
     * @param  array<string, mixed>  $parsedFilters
     */
    public function merge(User $user, SearchFilters $filters, array $parsedFilters, string $readerCurrency): SearchFilters
    {
        $accounts = $filters->accounts;
        if (isset($parsedFilters['accounts']) && is_array($parsedFilters['accounts'])) {
            $names = array_values(array_filter(
                $parsedFilters['accounts'],
                static fn (mixed $n): bool => is_string($n) && $n !== '',
            ));
            if ($names !== []) {
                $resolved = self::orNoMatch($this->resolveAccountNamesToIds($user, $names));
                $accounts = array_values(array_unique([...$accounts, ...$resolved]));
            }
        }

        $categories = $filters->categories;
        if (isset($parsedFilters['category']) && is_string($parsedFilters['category']) && $parsedFilters['category'] !== '') {
            $resolved = self::orNoMatch($this->resolveCategoryNameToIds($user, $parsedFilters['category']));
            $categories = array_values(array_unique([...$categories, ...$resolved]));
        }

        $after = $filters->after;
        if (isset($parsedFilters['after']) && is_string($parsedFilters['after'])) {
            $after = $parsedFilters['after'];
        }

        $before = $filters->before;
        if (isset($parsedFilters['before']) && is_string($parsedFilters['before'])) {
            $before = $parsedFilters['before'];
        }

        $amountMin = $filters->amountMin;
        $amountMax = $filters->amountMax;
        if (isset($parsedFilters['amount']) && is_string($parsedFilters['amount'])) {
            [$amountMin, $amountMax] = self::parseAmountToken($parsedFilters['amount'], $amountMin, $amountMax, $readerCurrency);
        }

        return new SearchFilters(
            accounts: $accounts,
            categories: $categories,
            // No counterparty: query token exists yet — pass the
            // chip/URL value through unchanged so this wholesale
            // rebuild never silently drops it.
            counterparties: $filters->counterparties,
            after: $after,
            before: $before,
            amountMin: $amountMin,
            amountMax: $amountMax,
            amountDirection: $filters->amountDirection,
            types: $filters->types,
            uncategorized: $filters->uncategorized,
        );
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function resolveAccountNamesToIds(User $user, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $query = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->where(function (Builder $match) use ($names): void {
                foreach ($names as $name) {
                    LikeNeedle::orStartsWithAnyCase($match, 'name', $name);
                }
            });

        return self::idList($query);
    }

    // A default category stores English and displays a translation, so the
    // reader's wording is not a column: the slugs it prefixes are named in PHP
    // and the rows matched in SQL. The stored name is still tried, so the
    // English and a rename both keep working.
    /**
     * @link ../../../../.docs/features/ledger/category-display-names.md
     *
     * @return list<int>
     */
    private function resolveCategoryNameToIds(User $user, string $name): array
    {
        $needle = mb_strtolower($name);

        $slugs = [];
        foreach (CategoryDisplayName::displayNamesBySlug() as $slug => $displayed) {
            if (self::startsWith($displayed, $needle)) {
                $slugs[] = $slug;
            }
        }

        $query = $this->db->connection()
            ->table('categories')
            ->where(function (Builder $scope) use ($user): void {
                $scope->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->where(function (Builder $match) use ($name, $slugs): void {
                LikeNeedle::startsWithAnyCase($match, 'name', $name);
                if ($slugs !== []) {
                    $match->orWhere(function (Builder $translated) use ($slugs): void {
                        $translated->where('name_is_default', true)->whereIn('slug', $slugs);
                    });
                }
            });

        return self::idList($query);
    }

    /**
     * @param  list<int>  $resolved
     * @return list<int>
     */
    private static function orNoMatch(array $resolved): array
    {
        return $resolved === [] ? [self::NO_SUCH_ID] : $resolved;
    }

    private static function startsWith(string $haystack, string $needle): bool
    {
        return $haystack !== '' && str_starts_with(mb_strtolower($haystack), $needle);
    }

    // Parses an amount: token into [min, max] decimal strings: >50
    // (min), <50 (max), 50-100 (range), bare 50 (exact). Falls back to the
    // existing values on an unrecognized token, and the fraction the two gated
    // shapes accept is the reader's own money's rather than a fixed two.
    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function parseAmountToken(string $token, ?string $currentMin, ?string $currentMax, string $readerCurrency): array
    {
        $token = trim($token);
        $normalize = static fn (string $v): string => str_replace(',', '.', $v);
        $decimals = MoneyInput::decimalPlaces($readerCurrency);
        $figure = '\d+'.($decimals === 0 ? '' : '(?:[.,]\d{1,'.$decimals.'})?');

        return match (true) {
            $token === '' => [$currentMin, $currentMax],
            str_starts_with($token, '>') => [$normalize(substr($token, 1)), $currentMax],
            str_starts_with($token, '<') => [$currentMin, $normalize(substr($token, 1))],
            preg_match('/^('.$figure.')-('.$figure.')$/', $token, $m) === 1 => [$normalize($m[1]), $normalize($m[2])],
            preg_match('/^'.$figure.'$/', $token) === 1 => [$normalize($token), $normalize($token)],
            default => [$currentMin, $currentMax],
        };
    }

    /**
     * @return list<int>
     */
    private static function idList(Builder $query): array
    {
        return array_values(array_map(self::toInt(...), $query->pluck('id')->all()));
    }
}
