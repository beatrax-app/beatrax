<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Enums;

enum ConfirmRefusal: string
{
    case AccountsToName = 'accounts_to_name';

    case NothingImportable = 'nothing_importable';

    public function sentence(): string
    {
        return match ($this) {
            self::AccountsToName => 'accounts the rows landed in are still unnamed',
            self::NothingImportable => 'not one of its rows can be imported',
        };
    }
}
