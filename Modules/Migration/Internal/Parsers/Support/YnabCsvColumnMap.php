<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;

/**
 * @link ../../../../../.docs/features/migration/architecture.md
 */
final class YnabCsvColumnMap
{
    /** @var list<string> */
    private const REGISTER_COMMON_HEADERS = ['Account', 'Date', 'Payee', 'Memo', 'Outflow', 'Inflow', 'Cleared'];

    /** @var list<string> */
    private const REGISTER_YNAB4_EXTRA_HEADERS = ['Master Category', 'Sub Category'];

    /** @var list<string> */
    private const REGISTER_NYNAB_EXTRA_HEADERS = ['Category Group/Category'];

    /** @var list<string> */
    private const BUDGET_HEADERS = ['Month', 'Category Group', 'Category', 'Budgeted'];

    /**
     * @param  array<string>  $header
     */
    public function assertRegisterHeader(array $header, string $format): void
    {
        $extra = $format === 'ynab4' ? self::REGISTER_YNAB4_EXTRA_HEADERS : self::REGISTER_NYNAB_EXTRA_HEADERS;
        $this->assertHeaderContains($header, [...self::REGISTER_COMMON_HEADERS, ...$extra], 'Register.csv');
    }

    /**
     * @param  array<string>  $header
     */
    public function assertBudgetHeader(array $header): void
    {
        $this->assertHeaderContains($header, self::BUDGET_HEADERS, 'Budget.csv');
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: ?string, 1: string}
     */
    public function categoryGroupAndName(array $row, string $format): array
    {
        // YNAB4 reads the two separate Master Category/Sub Category cells;
        // nYNAB splits the single combined "Category Group/Category" cell
        // (e.g. "Frequent: Groceries") on the first ':'. A transfer row
        // resolves to [null, ''].
        if ($format === 'ynab4') {
            $group = trim($row['Master Category'] ?? '');
            $name = trim($row['Sub Category'] ?? '');
        } else {
            $combined = trim($row['Category Group/Category'] ?? '');
            if ($combined === '') {
                return [null, ''];
            }
            $parts = explode(':', $combined, 2);
            $group = trim($parts[0]);
            $name = isset($parts[1]) ? trim($parts[1]) : '';
        }

        return [$group !== '' ? $group : null, $name];
    }

    /**
     * @param  array<string>  $header
     * @param  list<string>  $required
     */
    private function assertHeaderContains(array $header, array $required, string $fileLabel): void
    {
        foreach ($required as $expected) {
            if (! in_array($expected, $header, true)) {
                throw new UnrecognizedMigrationFileException(sprintf(
                    "%s: expected column '%s', found headers [%s]",
                    $fileLabel,
                    $expected,
                    implode(', ', $header),
                ));
            }
        }
    }
}
