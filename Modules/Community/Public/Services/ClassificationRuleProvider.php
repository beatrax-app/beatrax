<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Modules\Community\Internal\Corpus\CorpusYamlReader;
use Modules\Community\Public\Dto\ClassificationRule;
use Psr\Log\LoggerInterface;

final class ClassificationRuleProvider
{
    public const TYPE_GOVERNMENT = 'government';

    public const TYPE_BANK_FEES = 'bank-fees';

    /** @var array<string, list<ClassificationRule>> */
    private array $cache = [];

    public function __construct(
        private readonly CorpusYamlReader $reader,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<ClassificationRule>
     */
    public function governmentRules(?string $region = null): array
    {
        return $this->rulesForType(self::TYPE_GOVERNMENT, $region);
    }

    /**
     * @return list<ClassificationRule>
     */
    public function bankFeeRules(?string $region = null): array
    {
        return $this->rulesForType(self::TYPE_BANK_FEES, $region);
    }

    // A rule outside the reader's own country classified a Dutch health insurer
    // as a Belgian agency, because ZORGPREMIE is the ordinary Dutch word for a
    // health-insurance premium and every region's file was loaded at once. A
    // null region keeps that behaviour, for a reader who has named no country.
    /**
     * @return list<ClassificationRule>
     */
    private function rulesForType(string $type, ?string $region = null): array
    {
        $wanted = $region === null || $region === '' ? null : strtoupper($region);
        $key = $type.'|'.($wanted ?? '*');

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if ($wanted !== null) {
            return $this->cache[$key] = array_values(array_filter(
                $this->rulesForType($type),
                static fn (ClassificationRule $rule): bool => $rule->region === $wanted,
            ));
        }

        $dir = $this->reader->resolve('community.corpus.root', 'resources/corpus').'/'.$type;
        if (! is_dir($dir)) {
            $this->logger->warning('ClassificationRuleProvider: corpus directory is missing.', ['dir' => $dir]);

            return $this->cache[$key] = [];
        }

        $files = glob($dir.'/*.yaml');
        $rules = [];
        foreach ($files !== false ? $files : [] as $file) {
            $fileRegion = strtoupper(pathinfo($file, PATHINFO_FILENAME));
            foreach ($this->reader->readEntries($file) as $raw) {
                $rule = $this->buildRule($raw, $fileRegion);
                if ($rule !== null) {
                    $rules[] = $rule;
                }
            }
        }

        return $this->cache[$key] = $rules;
    }

    /**
     * @param  array<int|string, mixed>  $raw
     */
    private function buildRule(array $raw, string $region): ?ClassificationRule
    {
        $pattern = is_string($raw['pattern'] ?? null) ? trim($raw['pattern']) : '';
        if ($pattern === '') {
            return null;
        }

        $name = is_string($raw['name'] ?? null) && trim($raw['name']) !== '' ? trim($raw['name']) : null;

        return new ClassificationRule(pattern: $pattern, name: $name, region: $region);
    }
}
