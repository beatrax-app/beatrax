<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Pipeline;

use Illuminate\Contracts\Cache\Repository;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

/**
 * Holds the parsed canonical batch, the pending-enrichments list, and
 * the preview payload between the upload (preview) click and the
 * confirm click. 30-minute TTL.
 *
 * The cache round-trips DTOs via JSON + spatie/laravel-data hydration.
 * PHP's native object-deserialisation path is intentionally not used here:
 * even with `allowed_classes` it drives DTO constructors with arbitrary
 * cached strings, and the `JSON_THROW_ON_ERROR` flag makes any corruption
 * or schema skew loud rather than silently dropping rows.
 *
 * `Clock` is injected so the TTL window is deterministic in tests.
 */
final class PreviewCache
{
    public function __construct(
        private readonly Repository $cache,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  list<CanonicalTransaction>  $canonical
     * @param  list<PendingEnrichment>  $enrichments
     */
    public function put(int $importRunId, ImportPreviewResult $result, array $canonical, array $enrichments = []): void
    {
        $ttl = $this->clock->now()->addMinutes(30);

        $this->cache->put(
            $this->previewKey($importRunId),
            $result->toArray(),
            $ttl,
        );

        $canonicalPayload = json_encode(
            array_map(static fn (CanonicalTransaction $c): array => $c->toArray(), $canonical),
            JSON_THROW_ON_ERROR,
        );

        $this->cache->put(
            $this->canonicalKey($importRunId),
            $canonicalPayload,
            $ttl,
        );

        $enrichmentsPayload = json_encode(
            array_map(static fn (PendingEnrichment $p): array => $p->toArray(), $enrichments),
            JSON_THROW_ON_ERROR,
        );

        $this->cache->put(
            $this->enrichmentsKey($importRunId),
            $enrichmentsPayload,
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

    /**
     * Returns the cached pending-enrichments list for an import run, or
     * `null` when the cache entry is absent or expired. An empty list is
     * a legitimate value (a preview with no cross-format enrichments).
     *
     * @return list<PendingEnrichment>|null
     */
    public function getEnrichments(int $importRunId): ?array
    {
        $raw = $this->cache->get($this->enrichmentsKey($importRunId));
        if (! is_string($raw)) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $list */
        $list = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        return array_values(array_map(
            static fn (array $row): PendingEnrichment => PendingEnrichment::from($row),
            $list,
        ));
    }

    public function forget(int $importRunId): void
    {
        $this->cache->forget($this->previewKey($importRunId));
        $this->cache->forget($this->canonicalKey($importRunId));
        $this->cache->forget($this->enrichmentsKey($importRunId));
    }

    private function previewKey(int $importRunId): string
    {
        return sprintf('import.%d.preview', $importRunId);
    }

    private function canonicalKey(int $importRunId): string
    {
        return sprintf('import.%d.canonical', $importRunId);
    }

    private function enrichmentsKey(int $importRunId): string
    {
        return sprintf('import.%d.enrichments', $importRunId);
    }
}
