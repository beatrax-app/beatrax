<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Corpus;

use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Import\Public\Services\PatternGeneralizer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the bundled merchant corpus YAML files from disk (via the shared
 * CorpusYamlReader), validates each entry's required fields, computes the
 * generalized pattern via PatternGeneralizer, and returns a list of
 * CorpusEntryDto rows for the SeedCommunityCorpus listener to upsert into
 * the `community_merchant_mappings` table.
 *
 * Failure modes are tolerated per-entry, never globally:
 *
 *   - A missing or malformed YAML file (handled by CorpusYamlReader) yields
 *     an empty entry list and a warning; the other configured file still
 *     loads and the seed is not aborted.
 *   - An individual entry that lacks `pattern`, `name`, or `contributor`
 *     is logged at `warning` and dropped from the returned list.
 *   - An entry whose `category` is non-null but matches no row in the
 *     `categories` table is logged at `warning` and INCLUDED verbatim
 *     (graceful degradation — the consumer renders the unresolved category
 *     as a plain string).
 */
final class CorpusLoader
{
    /** @var list<string> Config keys for the bundled merchant corpus files. */
    private const MERCHANT_PATH_KEYS = [
        'community.corpus.bundled_path',
        'community.corpus.heuristics_path',
    ];

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

        $entries = [];
        foreach (self::MERCHANT_PATH_KEYS as $key) {
            $path = $this->reader->resolve($key);
            if ($path === '') {
                continue;
            }
            foreach ($this->reader->readEntries($path) as $raw) {
                $entry = $this->buildEntry($raw, $validCategories);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     * @param  array<string, true>  $validCategories
     */
    private function buildEntry(array $raw, array $validCategories): ?CorpusEntryDto
    {
        $pattern = is_string($raw['pattern'] ?? null) ? trim($raw['pattern']) : '';
        $name = is_string($raw['name'] ?? null) ? trim($raw['name']) : '';
        $contributor = is_string($raw['contributor'] ?? null) ? trim($raw['contributor']) : '';

        if ($pattern === '' || $name === '' || $contributor === '') {
            $this->logger->warning('CorpusLoader: corpus entry missing required fields.', [
                'pattern' => $pattern,
                'name' => $name,
                'contributor' => $contributor,
            ]);

            return null;
        }

        $category = isset($raw['category']) && is_string($raw['category'])
            ? trim($raw['category'])
            : null;
        if ($category === '') {
            $category = null;
        }

        $region = isset($raw['region']) && is_string($raw['region'])
            ? trim($raw['region'])
            : null;
        if ($region === '') {
            $region = null;
        }

        if ($category !== null && ! isset($validCategories[$category])) {
            $this->logger->warning('Corpus entry references unknown category.', [
                'pattern' => $pattern,
                'category' => $category,
            ]);
        }

        $generalized = isset($raw['generalized_pattern']) && is_string($raw['generalized_pattern'])
            ? trim($raw['generalized_pattern'])
            : '';
        if ($generalized === '') {
            $generalized = $this->generalizer->generalize($pattern);
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
