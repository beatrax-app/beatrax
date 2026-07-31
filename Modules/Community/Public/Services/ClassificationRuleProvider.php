<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Modules\Community\Internal\Corpus\CorpusYamlReader;
use Modules\Community\Public\Dto\ClassificationRule;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
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
    public function governmentRules(): array
    {
        return $this->rulesForType(self::TYPE_GOVERNMENT);
    }

    /**
     * @return list<ClassificationRule>
     */
    public function bankFeeRules(): array
    {
        return $this->rulesForType(self::TYPE_BANK_FEES);
    }

    /**
     * @return list<ClassificationRule>
     */
    private function rulesForType(string $type): array
    {
        if (isset($this->cache[$type])) {
            return $this->cache[$type];
        }

        $dir = $this->reader->resolve('community.corpus.root', 'resources/corpus').'/'.$type;
        if (! is_dir($dir)) {
            $this->logger->warning('ClassificationRuleProvider: corpus directory is missing.', ['dir' => $dir]);

            return $this->cache[$type] = [];
        }

        $files = glob($dir.'/*.yaml');
        $rules = [];
        foreach ($files !== false ? $files : [] as $file) {
            $region = strtoupper(pathinfo($file, PATHINFO_FILENAME));
            foreach ($this->reader->readEntries($file) as $raw) {
                $rule = $this->buildRule($raw, $region);
                if ($rule !== null) {
                    $rules[] = $rule;
                }
            }
        }

        return $this->cache[$type] = $rules;
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
