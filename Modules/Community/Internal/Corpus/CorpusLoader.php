<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Corpus;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Import\Public\Services\PatternGeneralizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads the bundled merchant-mappings.yaml and built-in-heuristics.yaml
 * files from disk, validates each entry's required fields, computes the
 * generalized pattern via PatternGeneralizer, and returns a list of
 * CorpusEntryDto rows for the SeedCommunityCorpus listener to upsert
 * into the `community_merchant_mappings` table.
 *
 * Failure modes are tolerated per-entry, never globally:
 *
 *   - A missing YAML file is logged at `warning` and skipped (the other
 *     configured file still loads); the loader does NOT abort the seed.
 *   - A malformed YAML file (parse error from symfony/yaml) is logged
 *     at `warning` and skipped — same posture.
 *   - An individual entry that lacks `pattern`, `name`, or `contributor`
 *     is logged at `warning` and dropped from the returned list. The
 *     other valid entries still load.
 *   - An entry whose `category` field is non-null but does not match
 *     any row in the `categories` table at load time is logged at
 *     `warning` and INCLUDED in the returned list with the unresolved
 *     category string preserved verbatim. Graceful degradation is the
 *     consumer's responsibility: the auto-suggest chip on the import
 *     preview renders the unresolved category as a plain string.
 *
 * symfony/yaml is invoked with `PARSE_EXCEPTION_ON_INVALID_TYPE` so a
 * YAML body that embeds PHP-object or other native-tag values raises
 * a parse exception rather than silently instantiating arbitrary
 * objects (ASVS V10 / T-16.1-05-01 in the threat model).
 */
final class CorpusLoader
{
    public function __construct(
        private readonly PatternGeneralizer $generalizer,
        private readonly LoggerInterface $logger,
        private readonly ConfigRepository $config,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return list<CorpusEntryDto>
     */
    public function loadBundled(): array
    {
        $validCategories = $this->fetchKnownCategoryNames();

        $entries = [];

        $bundledPath = $this->resolvePath((string) $this->config->get('community.corpus.bundled_path', ''));
        $heuristicsPath = $this->resolvePath((string) $this->config->get('community.corpus.heuristics_path', ''));

        foreach ([$bundledPath, $heuristicsPath] as $path) {
            if ($path === '') {
                continue;
            }
            foreach ($this->loadFile($path, $validCategories) as $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, true>  $validCategories
     * @return list<CorpusEntryDto>
     */
    private function loadFile(string $path, array $validCategories): array
    {
        if (! is_file($path)) {
            $this->logger->warning('CorpusLoader: bundled corpus file is missing.', ['path' => $path]);

            return [];
        }

        try {
            /** @var mixed $parsed */
            $parsed = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $this->logger->warning('CorpusLoader: failed to parse YAML.', [
                'path' => $path,
                'exception_message' => $e->getMessage(),
            ]);

            return [];
        } catch (Throwable $e) {
            $this->logger->warning('CorpusLoader: unexpected error reading YAML.', [
                'path' => $path,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);

            return [];
        }

        if (! is_array($parsed) || ! isset($parsed['entries']) || ! is_array($parsed['entries'])) {
            $this->logger->warning('CorpusLoader: YAML root has no `entries:` list.', ['path' => $path]);

            return [];
        }

        $entries = [];
        foreach ($parsed['entries'] as $raw) {
            $entry = $this->buildEntry($raw, $validCategories);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param  mixed  $raw
     * @param  array<string, true>  $validCategories
     */
    private function buildEntry(mixed $raw, array $validCategories): ?CorpusEntryDto
    {
        if (! is_array($raw)) {
            $this->logger->warning('CorpusLoader: corpus entry is not a mapping.', ['raw_type' => gettype($raw)]);

            return null;
        }

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

    /**
     * Resolve a configured path against the application root, leaving
     * absolute paths untouched so a test fixture under sys_get_temp_dir
     * loads from its full path.
     */
    private function resolvePath(string $configured): string
    {
        if ($configured === '') {
            return '';
        }
        if (str_starts_with($configured, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $configured) === 1) {
            return $configured;
        }
        $root = (string) $this->config->get('community.app_root', '');
        if ($root === '') {
            // Fall back to four-up from this file (Modules/Community/Internal/Corpus → repo root).
            $root = dirname(__DIR__, 4);
        }

        return rtrim($root, '/').'/'.$configured;
    }
}
