<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Enums;

enum ArgType: string
{
    case Text = 'text';

    case Select = 'select';

    case FilePath = 'file-path';

    case Boolean = 'boolean';
}
