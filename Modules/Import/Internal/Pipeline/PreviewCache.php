<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Holds the parsed canonical batch + preview payload between the upload
 * (preview) click and the confirm click. 30-minute TTL.
 *
 * **Locked contract (T-05-11):** the cache MUST round-trip via JSON +
 * spatie/laravel-data hydration. PHP's native object-deserialization path
 * is forbidden anywhere in this file (and indirectly anywhere on the
 * Internal/Pipeline path) because the deserialization-of-untrusted-data
 * class — even with `allowed_classes` — still drives DTO constructors with
 * attacker-controlled strings. The `JSON_THROW_ON_ERROR` flag makes any
 * corruption or version-skew loud rather than silently dropping rows.
 *
 * `Clock` is injected so the TTL window is deterministic in tests; the
 * global now-helper is banned per CLAUDE.md.
 */
final class PreviewCache
{
    public function __construct(
        private readonly Repository $cache,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<CanonicalTransaction>  $canonical
     */
    public function put(int $importRunId, ImportPreviewResult $result, array $canonical): void
    {
        $ttl = $this->clock->now()->addMinutes(30);

        $this->cache->put(
            $this->previewKey($importRunId),
            $result->toArray(),
            $ttl,
        );

        $payload = json_encode(
            array_map(static fn (CanonicalTransaction $c): array => $c->toArray(), $canonical),
            JSON_THROW_ON_ERROR,
        );

        $this->cache->put(
            $this->canonicalKey($importRunId),
            $payload,
            $ttl,
        );
    }

    public function getPreview(int $importRunId): ?ImportPreviewResult
    {
        $raw = $this->cache->get($this->previewKey($importRunId));
        if (! is_array($raw)) {
            return null;
        }

        return ImportPreviewResult::from($raw);
    }

    /**
     * Returns the cached canonical batch for an import run, or `null` when
     * the cache entry is absent or expired. Callers must distinguish
     * "missing" (null) from "empty list" — the latter is a legitimate
     * preview whose every row was a duplicate; the former means the
     * confirm step has no batch to replay and must surface a re-upload
     * prompt rather than confirming nothing.
     *
     * @return list<CanonicalTransaction>|null
     */
    public function getCanonical(int $importRunId): ?array
    {
        $raw = $this->cache->get($this->canonicalKey($importRunId));
        if (! is_string($raw)) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $list */
        $list = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_map(
            static fn (array $row): CanonicalTransaction => CanonicalTransaction::from($row),
            $list,
        ));
    }

    public function forget(int $importRunId): void
    {
        $this->cache->forget($this->previewKey($importRunId));
        $this->cache->forget($this->canonicalKey($importRunId));
    }

    private function previewKey(int $importRunId): string
    {
        return sprintf('import.%d.preview', $importRunId);
    }

    private function canonicalKey(int $importRunId): string
    {
        return sprintf('import.%d.canonical', $importRunId);
    }
}
