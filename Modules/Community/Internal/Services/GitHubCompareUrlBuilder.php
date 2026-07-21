<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Community\Public\Dto\SuggestMappingDto;

/**
 * @link ../../../../.docs/features/community/architecture.md
 */
final class GitHubCompareUrlBuilder
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function build(SuggestMappingDto $dto): string
    {
        $configured = $this->config->get(
            'community.github_compare_base',
            'https://github.com/nightworksio/beatrax/compare/main',
        );
        $base = is_string($configured)
            ? $configured
            : 'https://github.com/nightworksio/beatrax/compare/main';

        $branch = 'suggest-'.substr(hash('sha256', $dto->pattern), 0, 16);

        $bodyLines = [
            '  - pattern: '.$this->quoteYaml($dto->pattern),
            '    name: '.$this->quoteYaml($dto->name),
        ];
        if ($dto->category !== null && $dto->category !== '') {
            $bodyLines[] = '    category: '.$this->quoteYaml($dto->category);
        }
        $bodyLines[] = '    region: '.$this->quoteYaml($dto->region);
        $bodyLines[] = '    contributor: "user"';

        $body = "entries:\n".implode("\n", $bodyLines)."\n";

        return $base.'...'.$branch.'?expand=1&body='.rawurlencode($body);
    }

    private function quoteYaml(string $value): string
    {
        // YAML 1.2 double-quoted strings recognise the C-style escapes below;
        // a raw description with a stray newline/tab must be encoded as the
        // escape sequence rather than included verbatim, or GitHub's PR
        // composer rejects the YAML on submit.
        $escaped = strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);

        return '"'.$escaped.'"';
    }
}
