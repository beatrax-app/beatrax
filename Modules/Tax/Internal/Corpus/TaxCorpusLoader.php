<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Corpus;

use Modules\Tax\Public\Enums\TaxCountry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

// Every failure mode logs a warning and returns [] rather than throwing, so a
// bad corpus file cannot break the tax page. PARSE_EXCEPTION_ON_INVALID_TYPE
// stops a YAML native tag from instantiating an object.
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
            // Not an error: the caller may be probing whether a country exists.
            return [];
        }

        $parsed = $this->readCorpusYaml(resource_path("corpus/tax/{$code}.yaml"));

        return $this->extractEntries($parsed);
    }

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
            // readCorpusYaml already logged the failure; do not double-warn.
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
