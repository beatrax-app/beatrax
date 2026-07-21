<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Corpus;

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Community\Public\Services\CorpusPatternMatcher;
use Modules\Import\Public\Services\PatternGeneralizer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
final class CorpusLoader
{
    private const DEFAULT_CONTRIBUTOR = 'beatrax-bot';

    public function __construct(
        private readonly PatternGeneralizer $generalizer,
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $db,
        private readonly CorpusYamlReader $reader,
    ) {}

    /**
     * @return list<CorpusEntryDto>
     */
    public function loadBundled(): array
    {
        $validCategories = $this->fetchKnownCategoryNames();

        $dir = $this->reader->resolve('community.corpus.root', 'resources/corpus').'/merchants';
        if (! is_dir($dir)) {
            $this->logger->warning('CorpusLoader: merchants corpus directory is missing.', ['dir' => $dir]);

            return [];
        }

        $files = glob($dir.'/*.yaml');
        if ($files === false) {
            return [];
        }
        sort($files);

        $entries = [];
        foreach ($files as $file) {
            $region = $this->regionFromFilename($file);
            foreach ($this->reader->readEntries($file) as $raw) {
                $entry = $this->buildEntry($raw, $validCategories, $region);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    private const REGION_MAX = 8;

    private function regionFromFilename(string $file): string
    {
        // A filename longer than the region column is not a valid ISO code,
        // so it is dropped to an empty region (with a warning) rather than
        // overflowing the column and making a strict DB driver reject the
        // whole file's rows.
        $region = strtoupper(pathinfo($file, PATHINFO_FILENAME));
        if (mb_strlen($region) > self::REGION_MAX) {
            $this->logger->warning('CorpusLoader: corpus filename is not a valid region code; region left empty.', [
                'file' => $file,
            ]);

            return '';
        }

        return $region;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     * @param  array<string, true>  $validCategories
     * @param  string  $defaultRegion  ISO code inferred from the source filename
     */
    private function buildEntry(array $raw, array $validCategories, string $defaultRegion): ?CorpusEntryDto
    {
        $pattern = is_string($raw['pattern'] ?? null) ? trim($raw['pattern']) : '';
        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';

        if ($pattern === '' || $name === '') {
            $this->logger->warning('CorpusLoader: corpus entry missing required fields.', [
                'pattern' => $pattern,
                'name' => $name,
            ]);

            return null;
        }

        $contributor = is_string($raw['contributor'] ?? null) && trim($raw['contributor']) !== ''
            ? trim($raw['contributor'])
            : self::DEFAULT_CONTRIBUTOR;

        $category = isset($raw['category']) && is_string($raw['category'])
            ? trim($raw['category'])
            : null;
        if ($category === '') {
            $category = null;
        }

        $region = isset($raw['region']) && is_string($raw['region']) && trim($raw['region']) !== ''
            ? trim($raw['region'])
            : $defaultRegion;

        if ($category !== null && ! isset($validCategories[$category])) {
            $this->logger->warning('Corpus entry references unknown category.', [
                'pattern' => $pattern,
                'category' => $category,
            ]);
        }

        // A `regex:` pattern is matched verbatim by CorpusPatternMatcher, so
        // it must NOT be run through the substring generalizer (which would
        // produce a meaningless token). Its generalized_pattern is left empty
        // so the substring scan skips it; the regex scan picks it up instead.
        if (str_starts_with($pattern, CorpusPatternMatcher::REGEX_PREFIX)) {
            $generalized = '';
        } else {
            $generalized = isset($raw['generalized_pattern']) && is_string($raw['generalized_pattern'])
                ? trim($raw['generalized_pattern'])
                : '';
            if ($generalized === '') {
                $generalized = $this->generalizer->generalize($pattern);
            }
        }

        return new CorpusEntryDto(
            pattern: $pattern,
            generalizedPattern: $generalized,
            name: $name,
            category: $category,
            region: $region,
            contributor: $contributor,
        );
    }

    /**
     * @return array<string, true>
     */
    private function fetchKnownCategoryNames(): array
    {
        try {
            $rows = $this->db->connection()->table('categories')->pluck('name');
        } catch (Throwable $e) {
            $this->logger->warning('CorpusLoader: could not read categories table.', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return [];
        }

        $known = [];
        foreach ($rows as $name) {
            if (is_string($name) && $name !== '') {
                $known[$name] = true;
            }
        }

        return $known;
    }
}
