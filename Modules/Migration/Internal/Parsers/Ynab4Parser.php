<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers;

/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class Ynab4Parser extends AbstractYnabParser
{
    public function format(): string
    {
        return 'ynab4';
    }
}
