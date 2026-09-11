<?php

declare(strict_types=1);

use Modules\Sync\Internal\Config\CoveredTableOrder;
use Modules\Sync\Internal\Merge\RowOwnership;

// The same fact, written down twice: a column naming a row in a covered table
// with no foreign key to say so. RowOwnership reads its copy to check the id
// belongs to this reader; CoveredTableOrder reads its copy to translate the id,
// to send the parent beside the row, and to write the parent down first.

// Neither can derive it -- that is the whole point of the entry -- so the only
// thing keeping them the same is this.
function declaredUnconstrainedReferences(string $class, string $constant): array
{
    /** @var array<string, array<string, string>> $value */
    $value = (new ReflectionClass($class))->getConstant($constant);

    $flat = [];

    foreach ($value as $table => $columns) {
        foreach ($columns as $column => $target) {
            $flat[] = $table.'.'.$column.' -> '.$target;
        }
    }

    sort($flat);

    return $flat;
}

it('writes the unconstrained references down the same way in both places', function (): void {
    $ownership = declaredUnconstrainedReferences(RowOwnership::class, 'UNENFORCED_REFERENCES');
    $ordering = declaredUnconstrainedReferences(CoveredTableOrder::class, 'UNCONSTRAINED_PARENTS');

    expect($ownership)->not->toBe([], 'neither list was read, so agreeing means nothing');

    expect($ordering)->toBe($ownership, implode("\n", [
        'A column with no foreign key has to be declared twice, because four',
        'separate mechanisms need it and none can derive it: the ownership',
        'check, the id translation, the parent capture and the insertion order.',
        '',
        'Adding it to one and not the other is the failure this exists to stop:',
        'the reference was checked and never translated for as long as only',
        'RowOwnership knew about it.',
    ]));
});
