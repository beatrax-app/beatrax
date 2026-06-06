<?php

declare(strict_types=1);

namespace Modules\Community\Public\Services;

use Modules\Community\Internal\Corpus\CorpusYamlReader;
use Modules\Community\Public\Dto\ClassificationRule;
use Psr\Log\LoggerInterface;

/**
 * Loads the bundled, fixed classification rules — government agencies and
 * bank-fee keywords — from the per-country YAML files under
 * `resources/corpus/<type>/<country>.yaml`.
 *
 * These rules are NOT user-contributable (unlike the merchant corpus, which
 * lives in the database); they ship with the app and back the resolver's
 * government (step 5) and bank-fee (step 6) classification steps. Splitting
 * them out of hardcoded PHP constants lets a contributor add another
 * country's tax agency or fee wording — including `regex:` patterns — by
 * dropping a YAML file in, with no code change.
 *
 * Each file's country is inferred from its filename (`de.yaml` → `DE`,
 * `eu.yaml` → `EU`). Results are memoised for the life of the instance; the
 * service is registered as a singleton in CommunityServiceProvider so the
 * cache is shared across the request/worker.
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
                $pattern = is_string($raw['pattern'] ?? null) ? trim($raw['pattern']) : '';
                if ($pattern === '') {
                    continue;
                }
                $name = is_string($raw['name'] ?? null) && trim($raw['name']) !== '' ? trim($raw['name']) : null;
                $rules[] = new ClassificationRule(pattern: $pattern, name: $name, region: $region);
            }
        }

        return $this->cache[$type] = $rules;
    }
}
