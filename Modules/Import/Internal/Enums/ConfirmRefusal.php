<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Enums;

use Modules\Core\Public\Support\Lang;

enum ConfirmRefusal: string
{
    case AccountsToName = 'accounts_to_name';

    case NothingImportable = 'nothing_importable';

    case FileDidNotReadInFull = 'file_did_not_read_in_full';

    public function sentence(): string
    {
        return match ($this) {
            self::AccountsToName => 'accounts the rows landed in are still unnamed',
            self::NothingImportable => 'not one of its rows can be imported',
            self::FileDidNotReadInFull => 'reading it stopped before the end, so whatever it holds past that point was never seen',
        };
    }

    // sentence() is the exception's words: English, and carrying the run id its
    // caller puts beside them. A screen that leaves one run out of a staged
    // batch has to say why in the reader's own language instead.
    public function label(): string
    {
        return Lang::get('import::preview.refused.'.$this->value);
    }

    // Whether reading the source again could produce a run that confirms. An
    // unnamed account and a file of unimportable rows come back identical; a
    // read that stopped may have stopped on something that does not recur.
    public function anotherReadCouldDiffer(): bool
    {
        return $this === self::FileDidNotReadInFull;
    }
}
