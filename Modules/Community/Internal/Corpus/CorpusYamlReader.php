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
 * @link ../../../../.docs/features/community/architecture.md
 */
final class CorpusYamlReader
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ConfigRepository $config,
        private readonly Application $app,
    ) {}

    public function resolve(string $configKey, string $default = ''): string
    {
        // An empty result means "no path configured" (the caller skips) —
        // the single, shared meaning of an empty corpus path across every
        // consumer; `community.app_root` lets a test override the root with
        // a temporary fixture directory.
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
