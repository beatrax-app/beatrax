<?php

declare(strict_types=1);

namespace Modules\Search\Public\Contracts;

use Modules\Core\Models\User;

/**
 * Public contract for the palette search section provider.
 *
 * Implemented by Modules\Search\Internal\Services\SearchResultsProviderImpl (Plan 03).
 *
 * CommandPaletteModal injects this interface (as nullable) in Plan 05 to
 * receive the two mixed-type palette sections (transaction hits + entity name
 * hits) without importing any Search Internal class.
 *
 * @phpstan-type PaletteTransaction array{id: int, counterpartyName: ?string, amount: string, snippet: ?string, url: string}
 * @phpstan-type PaletteEntity array{id: int, type: string, label: string, url: string}
 */
interface SearchResultsProvider
{
    /**
     * Returns the top palette search sections for a user query.
     *
     * Sections:
     *   - `transactions`: top-5 transaction hits (counterparty + amount + snippet)
     *   - `entities`: top-5 entity name hits (counterparties, categories, goals, pots)
     *   - `totalCount`: total transaction hits across all pages
     *
     * @return array{transactions: list<array<string,mixed>>, entities: list<array<string,mixed>>, totalCount: int}
     */
    public function paletteSections(User $user, string $query): array;
}
