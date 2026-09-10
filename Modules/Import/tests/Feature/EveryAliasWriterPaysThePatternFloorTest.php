<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Import\Internal\Exceptions\MerchantAliasPatternTooShortException;
use Modules\Import\Internal\Services\AliasYamlImporter;
use Modules\Import\Public\Actions\CreateMerchantAlias;
use Modules\Import\Public\Actions\MergeMerchantAliases;
use Modules\Import\Public\Services\AliasMatchPreviewQuery;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
});

// The generalized pattern matches as a whole token against every description
// the reader owns, so a needle under the floor renames a ledger.
function aliasPatternsWrittenFor(int $userId): array
{
    return DB::table('merchant_aliases')
        ->where('user_id', $userId)
        ->pluck('generalized_pattern')
        ->map(static fn (mixed $pattern): int => mb_strlen((string) $pattern))
        ->all();
}

it('refuses a YAML entry whose generalisation falls under the floor', function (): void {
    $importer = app(AliasYamlImporter::class);

    // "AH 1234" keeps only "ah" once the terminal id is dropped, which matches
    // as a whole token against every description the reader owns.
    $parse = fn (): mixed => $importer->parse(<<<'YAML'
    entries:
      - pattern: "AH 1234"
        name: "Albert Heijn"
    YAML);

    expect($parse)->toThrow(MerchantAliasPatternTooShortException::class)
        ->and(aliasPatternsWrittenFor($this->fixtureUser->id))->toBe([]);
});

it('refuses a merge whose surviving pattern falls under the floor', function (): void {
    $create = app(CreateMerchantAlias::class);
    $first = ($create)($this->fixtureUser, 'ALBERT HEIJN 1234', 'albert heijn', 'Albert Heijn');
    $second = ($create)($this->fixtureUser, 'ALDI 5678', 'aldi', 'Aldi');

    $merge = fn (): mixed => app(MergeMerchantAliases::class)(
        $this->fixtureUser,
        [$first->id, $second->id],
        'Supermarket',
        'al',
    );

    expect($merge)->toThrow(MerchantAliasPatternTooShortException::class)
        ->and(aliasPatternsWrittenFor($this->fixtureUser->id))
        ->each->toBeGreaterThanOrEqual(AliasMatchPreviewQuery::MIN_PATTERN_LENGTH);
});
