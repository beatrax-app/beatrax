<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Enums;

use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\Lang;

// Why an alias file was refused, kept apart from the sentence that says so:
// the exception's message is machine text bound for the log, and the screen
// renders one of these instead. The position the entry cases carry is an
// ordinal into the file, not a count, so its line needs no plural arms.
enum AliasFileRejection: string
{
    case NotYaml = 'file_not_yaml';

    case UnreadableAsYaml = 'file_unreadable_as_yaml';

    case NoEntriesList = 'file_has_no_entries_list';

    case EntryIsNotAMapping = 'entry_is_not_a_mapping';

    case EntryIsMissingAField = 'entry_is_missing_a_field';

    public function sentence(int $entry): string
    {
        return Lang::get('import::aliases.errors.'.$this->value, ['entry' => Fmt::number($entry)]);
    }
}
