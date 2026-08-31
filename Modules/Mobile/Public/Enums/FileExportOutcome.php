<?php

declare(strict_types=1);

namespace Modules\Mobile\Public\Enums;

use Modules\Core\Public\Support\Lang;

// What became of a file the app tried to hand to the OS, in the reader's own
// words. There is deliberately no fourth case for "nothing happened": a
// download that vanishes without a sentence is the defect this enum exists to
// make impossible to write.
enum FileExportOutcome: string
{
    case Shared = 'shared';

    case Unsupported = 'unsupported';

    case Failed = 'failed';

    public function message(): string
    {
        return Lang::get('mobile::export.'.$this->value);
    }
}
