<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;

/**
 * Applies pending cross-format enrichments produced by the import
 * pipeline. Each enrichment is wrapped in a per-row DB transaction with
 * `lockForUpdate()` so two concurrent imports targeting the same row's
 * source_ref either serialise or short-circuit on the ref-equality
 * check rather than racing on the UPDATE.
 *
 * The enriched_from JSON column accumulates a full append-only
 * provenance trail. Every successful application appends one entry of
 * the shape:
 *
 *     ['format' => string, 'ran_at' => string, 'import_run_id' => int, 'added' => list<string>]
 *
 * The row's source_ref is overwritten with the stronger incoming
 * reference. Caller scoping by user is enforced inside the UPDATE so a
 * forged PendingEnrichment whose existingTransactionId belongs to
 * another user resolves zero rows and is silently dropped.
 */
final class ApplyEnrichments implements AppliesEnrichments
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    public function __invoke(array $enrichments, User $user): int
    {
        if ($enrichments === []) {
            return 0;
        }

        $count = 0;
        foreach ($enrichments as $enrichment) {
            if ($this->applyOne($enrichment, $user)) {
                $count++;
            }
        }

        return $count;
    }

    private function applyOne(PendingEnrichment $enrichment, User $user): bool
    {
        $applied = $this->db->connection()->transaction(function () use ($enrichment, $user): bool {
            $row = $this->db->connection()
                ->table('transactions')
                ->where('id', $enrichment->existingTransactionId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first(['id', 'source_ref', 'enriched_from']);

            if ($row === null) {
                return false;
            }

            $existingRef = is_string($row->source_ref) ? $row->source_ref : null;
            if ($existingRef !== null && $existingRef === $enrichment->newSourceRef) {
                return false;
            }

            $rawEnrichedFrom = is_string($row->enriched_from) ? $row->enriched_from : null;
            $provenance = $this->decodeEnrichedFrom($rawEnrichedFrom);
            $provenance[] = [
                'format' => $enrichment->sourceFormat,
                'ran_at' => $this->clock->now()->toIso8601String(),
                'import_run_id' => $enrichment->importRunId,
                'added' => ['source_ref'],
            ];

            $this->db->connection()
                ->table('transactions')
                ->where('id', $enrichment->existingTransactionId)
                ->where('user_id', $user->id)
                ->update([
                    'source_ref' => $enrichment->newSourceRef,
                    'enriched_from' => json_encode($provenance, JSON_THROW_ON_ERROR),
                    'updated_at' => $this->clock->now()->toDateTimeString(),
                ]);

            return true;
        });

        return $applied === true;
    }

    /**
     * @return list<array{format: string, ran_at: string, import_run_id: int, added: list<string>}>
     */
    private function decodeEnrichedFrom(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            return [];
        }

        /** @var list<array{format: string, ran_at: string, import_run_id: int, added: list<string>}> $entries */
        $entries = array_values($decoded);

        return $entries;
    }
}
