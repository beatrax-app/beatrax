<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Corpus;

use Modules\Tax\Public\Enums\TaxCountry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

// Failure modes are tolerated and logged, never thrown, following the
// CorpusYamlReader convention from the Community module: unknown
// country / malformed YAML / missing entries all log a warning and
// return []. PARSE_EXCEPTION_ON_INVALID_TYPE guards against native-tag object instantiation.
final class TaxCorpusLoader
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  string  $countryCode  ISO 3166 alpha-2, lowercase (e.g. 'nl')
     * @return list<array<int|string, mixed>> Each entry has at least 'key' and 'name'.
     */
    public function loadForCountry(string $countryCode): array
    {
        $code = strtolower(trim($countryCode));

        if (TaxCountry::tryFrom($code) === null) {
            // Not an error — the caller may be probing an unavailable
            // country for availability.
            return [];
        }

        $parsed = $this->readCorpusYaml(resource_path("corpus/tax/{$code}.yaml"));

        return $this->extractEntries($parsed);
    }

    // Reads and decodes a corpus file, tolerating every failure mode with a
    // logged warning and a null return: a missing file, a ParseException
    // (including the native-tag object guard), or any other read error.
    private function readCorpusYaml(string $path): mixed
    {
        if (! is_file($path)) {
            $this->logger->warning('TaxCorpusLoader: corpus file is missing.', ['path' => $path]);

            return null;
        }

        try {
            return Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $this->logger->warning('TaxCorpusLoader: failed to parse YAML.', [
                'path' => $path,
                'exception_message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('TaxCorpusLoader: unexpected error reading YAML.', [
                'path' => $path,
                'exception_class' => $e::class,
            ]);
        }

        return null;
    }

    /**
     * @return list<array<int|string, mixed>> Each entry has at least 'key' and 'name'.
     */
    private function extractEntries(mixed $parsed): array
    {
        if ($parsed === null) {
            // A null here means readCorpusYaml already logged the missing-file
            // or parse failure; stay quiet rather than double-warning.
            return [];
        }

        if (! is_array($parsed) || ! isset($parsed['entries']) || ! is_array($parsed['entries'])) {
            $this->logger->warning('TaxCorpusLoader: YAML root has no `entries:` list.');

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
