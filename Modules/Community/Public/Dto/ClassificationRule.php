<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

/**
 * One classification rule loaded from a corpus YAML file (government/* or
 * bank-fees/*). The pattern is a literal substring or a `regex:` body
 * (see CorpusPatternMatcher); `name` is the optional canonical display
 * name; `region` is the ISO country code inferred from the source
 * filename (e.g. `de.yaml` → `DE`, `eu.yaml` → `EU`).
 */
final class ClassificationRule
{
    public function __construct(
        public readonly string $pattern,
        public readonly ?string $name,
        public readonly string $region,
    ) {}
}
