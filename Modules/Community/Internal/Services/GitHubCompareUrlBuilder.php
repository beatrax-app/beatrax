<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Community\Public\Dto\SuggestMappingDto;

/**
 * Builds the GitHub Compare URL the SuggestMappingModal launches in
 * the system browser when the user submits a suggested mapping. The
 * URL takes the canonical shape:
 *
 *   {base}...{branchSlug}?expand=1&body={url-encoded-yaml-entry}
 *
 * `{base}` is `config('community.github_compare_base')` so the repo
 * destination can be flipped at the Phase 19 (public-release) boundary
 * via the `BEATRAX_GITHUB_COMPARE_BASE` env var without a code change.
 *
 * `{branchSlug}` is a deterministic hash of the pattern field so a
 * given mapping always lands on the same proposed branch — the user
 * can iterate on a single suggestion (the Compare URL stays the same
 * across sessions) without polluting the upstream repo's branch list.
 *
 * `{body}` is a url-encoded snippet of YAML the user can paste-and-
 * commit into the corpus file via the GitHub PR composer. All user-
 * supplied fields are wrapped in YAML double-quotes with embedded
 * quotes escaped so a name like `"Bob's Burgers"` round-trips
 * cleanly.
 */
final class GitHubCompareUrlBuilder
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function build(SuggestMappingDto $dto): string
    {
        $base = (string) $this->config->get(
            'community.github_compare_base',
            'https://github.com/nightworksio/beatrax/compare/main',
        );

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
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
