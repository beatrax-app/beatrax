<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Corpus;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Loads the bundled per-country tax deduction category corpus from
 * `resources/corpus/tax/{cc}.yaml`. Each file contains an `entries:` list
 * where every entry has at minimum a `key` (corpus slug) and a `name`.
 *
 * Failure modes are tolerated and logged — never thrown — following the
 * CorpusYamlReader convention established in the Community module:
 *   - Unknown country code (no file) → returns [].
 *   - Missing or malformed YAML file → logs warning, returns [].
 *   - Root has no `entries:` list → logs warning, returns [].
 *
 * T-07-11 mitigated: Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE prevents native-tag
 * object instantiation in the bundled corpus files.
 */
final class TaxCorpusLoader
{
    /** @var list<string> Allow-listed country codes. */
    private const ALLOWED_COUNTRIES = ['nl', 'de', 'be', 'fr', 'gb', 'us'];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Load the deduction category entries for the given country code.
     *
     * @param  string  $countryCode  ISO-3166 alpha-2, lowercase (e.g. 'nl')
     * @return list<array<int|string, mixed>> Each entry has at least 'key' and 'name'.
     */
    public function loadForCountry(string $countryCode): array
    {
        $code = strtolower(trim($countryCode));

        if (! in_array($code, self::ALLOWED_COUNTRIES, strict: true)) {
            // Unknown country code: not an error — caller may probe for availability.
            return [];
        }

        $path = resource_path("corpus/tax/{$code}.yaml");

        if (! is_file($path)) {
            $this->logger->warning('TaxCorpusLoader: corpus file is missing.', ['path' => $path]);

            return [];
        }

        try {
            /** @var mixed $parsed */
            $parsed = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $this->logger->warning('TaxCorpusLoader: failed to parse YAML.', [
                'path' => $path,
                'exception_message' => $e->getMessage(),
            ]);

            return [];
        } catch (Throwable $e) {
            $this->logger->warning('TaxCorpusLoader: unexpected error reading YAML.', [
                'path' => $path,
                'exception_class' => $e::class,
            ]);

            return [];
        }

        if (! is_array($parsed) || ! isset($parsed['entries']) || ! is_array($parsed['entries'])) {
            $this->logger->warning('TaxCorpusLoader: YAML root has no `entries:` list.', ['path' => $path]);

            return [];
        }

        /** @var list<array<int|string, mixed>> $entries */
        $entries = [];
        foreach ($parsed['entries'] as $raw) {
            if (is_array($raw) && isset($raw['key'], $raw['name'])
                && is_string($raw['key']) && is_string($raw['name'])
                && $raw['key'] !== '' && $raw['name'] !== '') {
                $entries[] = $raw;
            }
        }

        return $entries;
    }
}
