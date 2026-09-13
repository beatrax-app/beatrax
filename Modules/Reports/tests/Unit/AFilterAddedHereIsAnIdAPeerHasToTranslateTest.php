<?php

declare(strict_types=1);

use Modules\Reports\Internal\Dto\ReportDefinition;

// Every list property here is stored verbatim inside saved_reports.definition,
// which travels. Two devices used before pairing mint different ids for the
// same account, so the peer's number names a different bank and the same named
// report counts different money — silently, because a filter that resolves to
// nothing just shows a smaller figure.

// The rewrite that stops that is declared per KEY in
// CoveredTableOrder::JSON_PARENTS, and nothing in the schema can notice a new
// key appearing here. This is the notice.
const FILTERS_TRANSLATED_ON_ARRIVAL = ['accounts', 'categories', 'counterparties'];

/**
 * @return list<string>
 */
function reportDefinitionListFilters(): array
{
    $filters = [];

    foreach ((new ReflectionClass(ReportDefinition::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && $type->getName() === 'array') {
            $filters[] = $parameter->getName();
        }
    }

    sort($filters);

    return $filters;
}

it('declares every id-list filter where the merge layer translates it', function (): void {
    $filters = reportDefinitionListFilters();

    expect($filters)->not->toBe([], 'no array filter was found at all, so this agrees with nothing');

    $expected = FILTERS_TRANSLATED_ON_ARRIVAL;
    sort($expected);

    expect($filters)->toBe($expected, implode("\n", [
        'A filter holding row ids was added to or removed from ReportDefinition.',
        'The stored definition travels whole, so each of these keys has to be',
        'declared in Modules/Sync CoveredTableOrder::JSON_PARENTS under',
        "saved_reports.definition as '<key>.*' => '<table>', or the peer stores",
        'the ids the other device minted and the report counts other money.',
        '',
        'Update both, then update the list here.',
    ]));
});
