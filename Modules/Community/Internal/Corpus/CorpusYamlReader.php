<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Corpus;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Shared filesystem + YAML access for the bundled corpus, used by both
 * CorpusLoader (merchant corpus) and ClassificationRuleProvider
 * (government / bank-fee rules). Centralising it keeps path resolution and
 * the YAML threat-model (PARSE_EXCEPTION_ON_INVALID_TYPE — no native-tag
 * object instantiation) in one place instead of two divergent copies.
 *
 * All failures are tolerated and logged at warning level, never thrown: a
 * missing or malformed file yields an empty entry list so one bad file can
 * never abort a corpus load.
 */
final class CorpusYamlReader
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ConfigRepository $config,
        private readonly Application $app,
    ) {}

    /**
     * Resolve a configured corpus path (relative to the app root) to an
     * absolute path. The `community.app_root` config override lets a test
     * point the loader at a temporary fixture directory.
     *
     * Empty config falls back to $default; an empty result means "no path
     * configured" (the caller skips) — this is the single, shared meaning
     * of an empty corpus path across every consumer.
     */
    public function resolve(string $configKey, string $default = ''): string
    {
        $configured = $this->stringConfig($configKey);
        if ($configured === '') {
            $configured = $default;
        }
        if ($configured === '') {
            return '';
        }

        if (str_starts_with($configured, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $configured) === 1) {
            return $configured;
        }

        $root = $this->stringConfig('community.app_root');
        if ($root === '') {
            $root = $this->app->basePath();
        }

        return rtrim($root, '/').'/'.$configured;
    }

    /**
     * Read the `entries:` list from a corpus YAML file as raw mappings.
     * Returns an empty list (with a warning) when the file is missing,
     * unparseable, or has no `entries:` root.
     *
     * @return list<array<int|string, mixed>>
     */
    public function readEntries(string $path): array
    {
        if (! is_file($path)) {
            $this->logger->warning('CorpusYamlReader: corpus file is missing.', ['path' => $path]);

            return [];
        }

        try {
            /** @var mixed $parsed */
            $parsed = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $this->logger->warning('CorpusYamlReader: failed to parse YAML.', [
                'path' => $path,
                'exception_message' => $e->getMessage(),
            ]);

            return [];
        } catch (Throwable $e) {
            $this->logger->warning('CorpusYamlReader: unexpected error reading YAML.', [
                'path' => $path,
                'exception_class' => $e::class,
            ]);

            return [];
        }

        if (! is_array($parsed) || ! isset($parsed['entries']) || ! is_array($parsed['entries'])) {
            $this->logger->warning('CorpusYamlReader: YAML root has no `entries:` list.', ['path' => $path]);

            return [];
        }

        $entries = [];
        foreach ($parsed['entries'] as $raw) {
            if (is_array($raw)) {
                $entries[] = $raw;
            }
        }

        return $entries;
    }

    private function stringConfig(string $key): string
    {
        $value = $this->config->get($key, '');

        return is_string($value) ? $value : '';
    }
}
