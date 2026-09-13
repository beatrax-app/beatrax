<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Concerns;

use League\Csv\Reader;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\Support\YnabCsvColumnMap;
use Throwable;

trait ReadsYnabCsvFiles
{
    /**
     * @return array{0: string, 1: string}
     */
    private function locateFiles(string $extractedPath): array
    {
        // Matched on suffix because the budget-name prefix is user-chosen, and
        // before any CSV parsing so an unrelated directory fails fast.
        $globbedRegister = glob($extractedPath.'/*Register.csv');
        $registerCandidates = $globbedRegister === false ? [] : $globbedRegister;
        $globbedBudget = glob($extractedPath.'/*Budget.csv');
        $budgetCandidates = $globbedBudget === false ? [] : $globbedBudget;

        if (count($registerCandidates) !== 1 || count($budgetCandidates) !== 1) {
            throw new UnrecognizedMigrationFileException(sprintf(
                "expected exactly one '*Register.csv' and one '*Budget.csv' file in '%s', found %d and %d",
                $extractedPath,
                count($registerCandidates),
                count($budgetCandidates),
            ));
        }

        return [$registerCandidates[0], $budgetCandidates[0]];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readRegisterRows(string $path, string $format): array
    {
        // The header is validated BEFORE any record is read, so a
        // missing/renamed column fails loud, never a partial import.
        $reader = $this->openReader($path, YnabCsvColumnMap::REGISTER_FILE);
        $this->columnMap->assertRegisterHeader($this->readerHeader($reader, YnabCsvColumnMap::REGISTER_FILE), $format);

        return $this->readerRows($reader, YnabCsvColumnMap::REGISTER_FILE);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readBudgetRows(string $path): array
    {
        $reader = $this->openReader($path, YnabCsvColumnMap::BUDGET_FILE);
        $this->columnMap->assertBudgetHeader($this->readerHeader($reader, YnabCsvColumnMap::BUDGET_FILE));

        return $this->readerRows($reader, YnabCsvColumnMap::BUDGET_FILE);
    }

    /**
     * @return Reader<array<array-key, mixed>>
     */
    private function openReader(string $path, string $fileLabel): Reader
    {
        try {
            $reader = Reader::from($path, 'r');
            $reader->setHeaderOffset(0);

            return $reader;
        } catch (Throwable $e) {
            throw new UnrecognizedMigrationFileException(sprintf('could not open %s: ', $fileLabel).$e->getMessage());
        }
    }

    /**
     * @param  Reader<array<array-key, mixed>>  $reader
     * @return array<string>
     */
    private function readerHeader(Reader $reader, string $fileLabel): array
    {
        try {
            return $reader->getHeader();
        } catch (Throwable $e) {
            throw new UnrecognizedMigrationFileException(sprintf('could not read %s header: ', $fileLabel).$e->getMessage());
        }
    }

    /**
     * @param  Reader<array<array-key, mixed>>  $reader
     * @return array<int, array<string, string>>
     */
    private function readerRows(Reader $reader, string $fileLabel): array
    {
        $rows = [];
        foreach ($reader->getRecords() as $record) {
            $rows[] = $this->normalizeRow($record, $fileLabel);
        }

        return $rows;
    }

    // A cell that is not valid UTF-8 is bytes, not text, and every reader past
    // this one takes it for text: the name reached a slug resolver mid-promote
    // and threw there, with the categories and the budget grid already written.
    /**
     * @param  array<array-key, mixed>  $record
     * @return array<string, string>
     */
    private function normalizeRow(array $record, string $fileLabel): array
    {
        $row = [];
        foreach ($record as $key => $value) {
            if ($value !== null && ! is_string($value)) {
                throw new UnrecognizedMigrationFileException('unexpected non-string CSV cell value');
            }
            $cell = $value ?? '';
            if (! mb_check_encoding($cell, 'UTF-8')) {
                throw UnrecognizedMigrationFileException::cell($fileLabel, (string) $key, $cell, 'expected UTF-8 text');
            }
            $row[(string) $key] = $cell;
        }

        return $row;
    }
}
