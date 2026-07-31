<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Concerns;

use League\Csv\Reader;
use Modules\Migration\Public\Exceptions\UnrecognizedMigrationFileException;
use Throwable;

// Locating the Register.csv/Budget.csv pair and reading each into a
// normalised row array is the "get bytes off disk" half of the YNAB
// parsers, kept apart from the "turn rows into DTOs" half so the parser
// class stays focused on mapping, not file handling.
/**
 * @link ../../../../../.docs/features/migration/architecture.md
 */
trait ReadsYnabCsvFiles
{
    /**
     * @return array{0: string, 1: string}
     */
    private function locateFiles(string $extractedPath): array
    {
        // Locates the Register.csv/Budget.csv pair by filename suffix (the
        // budget-name prefix is user-chosen, not fixed) BEFORE any CSV
        // parsing is attempted, so an unrelated directory fails fast.
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
        $reader = $this->openReader($path, 'Register.csv');
        $this->columnMap->assertRegisterHeader($this->readerHeader($reader, 'Register.csv'), $format);

        return $this->readerRows($reader);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readBudgetRows(string $path): array
    {
        $reader = $this->openReader($path, 'Budget.csv');
        $this->columnMap->assertBudgetHeader($this->readerHeader($reader, 'Budget.csv'));

        return $this->readerRows($reader);
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
            throw new UnrecognizedMigrationFileException("could not open {$fileLabel}: ".$e->getMessage());
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
            throw new UnrecognizedMigrationFileException("could not read {$fileLabel} header: ".$e->getMessage());
        }
    }

    /**
     * @param  Reader<array<array-key, mixed>>  $reader
     * @return array<int, array<string, string>>
     */
    private function readerRows(Reader $reader): array
    {
        $rows = [];
        foreach ($reader->getRecords() as $record) {
            $rows[] = $this->normaliseRow($record);
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $record
     * @return array<string, string>
     */
    private function normaliseRow(array $record): array
    {
        $row = [];
        foreach ($record as $key => $value) {
            if ($value !== null && ! is_string($value)) {
                throw new UnrecognizedMigrationFileException('unexpected non-string CSV cell value');
            }
            $row[(string) $key] = $value ?? '';
        }

        return $row;
    }
}
