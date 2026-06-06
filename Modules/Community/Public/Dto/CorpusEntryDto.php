<?php

declare(strict_types=1);

namespace Modules\Community\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Immutable representation of one row in the bundled merchant corpus
 * (the per-country files under `resources/corpus/merchants/`). The
 * CorpusLoader produces a list of these from the parsed YAML; the
 * SeedCommunityCorpus listener upserts each into the
 * `community_merchant_mappings` table keyed on `(pattern, user_id IS NULL)`.
 * For a literal pattern the `generalizedPattern` is computed at load time
 * via PatternGeneralizer so the resolver's community-tail substring match
 * runs against the same generalized shape user aliases carry; for a
 * `regex:` pattern it is empty (the row is matched by the regex scan).
 */
final class CorpusEntryDto extends Data
{
    public function __construct(
        public readonly string $pattern,
        public readonly string $generalizedPattern,
        public readonly string $name,
        public readonly ?string $category,
        public readonly ?string $region,
        public readonly string $contributor,
    ) {}
}
