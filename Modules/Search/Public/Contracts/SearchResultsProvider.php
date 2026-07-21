<?php

declare(strict_types=1);

namespace Modules\Search\Public\Contracts;

use Modules\Core\Models\User;

// CommandPaletteModal (DevMode) injects this as nullable to receive
// the palette's two mixed-type sections without importing any Search
// Internal class.
/**
 * @phpstan-type PaletteTransaction array{id: int, counterpartyName: ?string, amount: string, snippet: ?string, url: string}
 * @phpstan-type PaletteEntity array{id: int, type: string, label: string, url: string}
 */
interface SearchResultsProvider
{
    /**
     * @return array{transactions: list<array<string,mixed>>, entities: list<array<string,mixed>>, totalCount: int}
     */
    public function paletteSections(User $user, string $query): array;
}
