<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;

/**
 * Header allow-list + category-column resolution shared by `Ynab4Parser` and
 * `NynabParser` — RESEARCH.md's Format Schemas section identifies the
 * combined `"Category Group/Category"` column (nYNAB) vs the separate
 * `"Master Category"`/`"Sub Category"` columns (YNAB4) as the ONLY confirmed
 * divergence between the two formats; every other column and convention
 * (splits, transfers, cleared codes, amount format) is identical, which is
 * why this class takes `$format` per call rather than being format-bound —
 * a single shared instance serves both parsers.
 *
 * Stateless / singleton-safe. Every `assert*Header()` method throws
 * `UnrecognizedMigrationFileException` naming BOTH the expected column and
 * the full found header list (Req 1 / Open Q1's documented column
 * allow-list assumption) — never a partial/silent skip.
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
     * Resolves one Register.csv row's category group + name per format:
     * YNAB4 reads the two separate `Master Category`/`Sub Category` cells;
     * nYNAB splits the single combined `"Category Group/Category"` cell
     * (e.g. `"Frequent: Groceries"`) on the first `:` . A transfer row
     * (whose category columns are blank) resolves to `[null, '']`.
     *
     * @param  array<string, string>  $row
     * @return array{0: ?string, 1: string}
     */
    public function categoryGroupAndName(array $row, string $format): array
    {
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
