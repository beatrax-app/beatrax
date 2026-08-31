<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\DateFactory;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Import\Models\MerchantAlias;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Sync\Public\Events\EntityMutated;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class MergeMerchantAliases
{
    public function __construct(
        private DatabaseManager $db,
        private DateFactory $dates,
        private Dispatcher $events,
        private MerchantNameResolver $resolver,
    ) {}

    /**
     * @param  list<int>  $aliasIds
     */
    public function __invoke(
        User $user,
        array $aliasIds,
        string $friendlyName,
        string $generalizedPattern,
    ): MerchantAlias {
        if (count($aliasIds) < 2) {
            throw new InvalidArgumentException(
                'MergeMerchantAliases requires at least two alias ids.',
            );
        }

        $expectedCount = count(array_unique($aliasIds));

        /** @var list<EntityMutated> $captured */
        $captured = [];

        /** @var MerchantAlias $surviving */
        $surviving = $this->db->connection()->transaction(function () use (
            $user,
            $aliasIds,
            $expectedCount,
            $friendlyName,
            $generalizedPattern,
            &$captured,
        ): MerchantAlias {
            $rows = $this->loadAliasRows($user, $aliasIds, $expectedCount);

            /** @var stdClass $survivingRow */
            $survivingRow = $rows->shift();
            $absorbed = $rows;
            $survivingId = isset($survivingRow->id) && is_numeric($survivingRow->id) ? (int) $survivingRow->id : 0;

            $mergedFrom = $this->buildMergedFrom($survivingRow, $absorbed);

            $this->rewriteSurviving($user, $survivingId, $friendlyName, $generalizedPattern, $mergedFrom);
            $absorbedIds = $this->deleteAbsorbed($user, $absorbed);

            $captured = $this->captureFor($user, $survivingId, $friendlyName, $generalizedPattern, $mergedFrom, $absorbedIds);

            return $this->reloadSurviving($user, $survivingId);
        });

        // Only once the rows are committed, mirroring AliasYamlImporter: a
        // rollback would otherwise leave the op log describing a merge no local
        // row matches, and the paired device would perform it anyway.
        foreach ($captured as $event) {
            $this->events->dispatch($event);
        }

        // A merge rewrites one alias and deletes the rest, so a memo not told
        // about it keeps answering with the absorbed names the reader merged
        // away.
        $this->resolver->forget($user->id);

        return $surviving;
    }

    // Three writes, three ops. Renaming a counterparty is one of the commonest
    // edits there is and none of it left the device.
    /**
     * @param  list<array{v: string, tag: string}>  $mergedFrom
     * @param  list<int>  $absorbedIds
     * @return list<EntityMutated>
     */
    private function captureFor(
        User $user,
        int $survivingId,
        string $friendlyName,
        string $generalizedPattern,
        array $mergedFrom,
        array $absorbedIds,
    ): array {
        $events = [new EntityMutated(
            table: 'merchant_aliases',
            pk: $survivingId,
            userId: $user->id,
            mutationType: 'edit',
            dirtyFields: [
                'friendly_name' => $friendlyName,
                'generalized_pattern' => $generalizedPattern,
                // The OR-Set wire shape, not the column's. `added` carries the
                // WHOLE set, not this merge's delta: the replayer resolves a
                // field from one batch's ops alone, so a lone delta would
                // project a set missing every earlier merge.
                'merged_from' => ['added' => $mergedFrom, 'removed' => []],
            ],
        )];

        foreach ($absorbedIds as $absorbedId) {
            $events[] = new EntityMutated(
                table: 'merchant_aliases',
                pk: $absorbedId,
                userId: $user->id,
                mutationType: 'delete',
            );
        }

        return $events;
    }

    /**
     * @param  list<int>  $aliasIds
     * @return Collection<int, stdClass>
     */
    private function loadAliasRows(User $user, array $aliasIds, int $expectedCount): Collection
    {
        $rows = $this->db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $user->id)
            ->whereIn('id', $aliasIds)
            ->orderBy('id')
            ->get(['id', 'pattern', 'generalized_pattern', 'friendly_name', 'merged_from']);

        if ($rows->count() !== $expectedCount) {
            throw new NotFoundHttpException('One or more aliases were not found for the current user.');
        }

        return $rows;
    }

    // Elements in the OR-Set shape the merge registry declares for this column
    // — {v, tag} pairs, keyed by a content-derived tag so two devices merging
    // the same aliases mint the same element and the union stays idempotent.
    /**
     * @param  Collection<int, stdClass>  $absorbed
     * @return list<array{v: string, tag: string}>
     */
    private function buildMergedFrom(stdClass $survivingRow, Collection $absorbed): array
    {
        $elements = self::decodeMergedFrom($survivingRow);
        $mergedAt = $this->dates->now()->toIso8601String();

        foreach ($absorbed as $row) {
            // Raw statement text carries the occasional non-UTF8 byte, and
            // json_encode() under JSON_THROW_ON_ERROR aborts the whole
            // transaction for one of them.
            $elements[self::tagFor($row, $mergedAt)] = self::elementValue($row, $mergedAt);
        }

        return self::asElementList($elements);
    }

    private static function tagFor(stdClass $row, string $mergedAt): string
    {
        return substr(hash('sha256', self::elementValue($row, $mergedAt)), 0, 32);
    }

    private static function elementValue(stdClass $row, string $mergedAt): string
    {
        return json_encode([
            'pattern' => self::coerceUtf8(self::rowString($row, 'pattern')),
            'generalized_pattern' => self::coerceUtf8(self::rowString($row, 'generalized_pattern')),
            'friendly_name' => self::coerceUtf8(self::rowString($row, 'friendly_name')),
            'merged_at' => $mergedAt,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, string>  $elements  tag => value
     * @return list<array{v: string, tag: string}>
     */
    private static function asElementList(array $elements): array
    {
        $list = [];

        foreach ($elements as $tag => $value) {
            $list[] = ['v' => $value, 'tag' => $tag];
        }

        return $list;
    }

    /**
     * @param  list<array{v: string, tag: string}>  $mergedFrom
     */
    private function rewriteSurviving(User $user, int $survivingId, string $friendlyName, string $generalizedPattern, array $mergedFrom): void
    {
        $this->db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $user->id)
            ->where('id', $survivingId)
            ->update([
                'friendly_name' => $friendlyName,
                'generalized_pattern' => $generalizedPattern,
                'merged_from' => json_encode($mergedFrom, JSON_THROW_ON_ERROR),
                'updated_at' => $this->dates->now()->toDateTimeString(),
            ]);
    }

    /**
     * @param  Collection<int, stdClass>  $absorbed
     * @return list<int> The ids actually deleted, for the tombstone ops.
     */
    private function deleteAbsorbed(User $user, Collection $absorbed): array
    {
        $absorbedIds = [];
        foreach ($absorbed as $row) {
            if (isset($row->id) && is_numeric($row->id)) {
                $absorbedIds[] = (int) $row->id;
            }
        }

        if ($absorbedIds !== []) {
            $this->db->connection()
                ->table('merchant_aliases')
                ->where('user_id', $user->id)
                ->whereIn('id', $absorbedIds)
                ->delete();
        }

        return $absorbedIds;
    }

    private function reloadSurviving(User $user, int $survivingId): MerchantAlias
    {
        /** @var MerchantAlias|null $refreshed */
        $refreshed = MerchantAlias::query()
            ->where('user_id', $user->id)
            ->where('id', $survivingId)
            ->first();

        if ($refreshed === null) {
            throw new NotFoundHttpException('The surviving alias row vanished mid-transaction.');
        }

        return $refreshed;
    }

    // Keyed by tag so a repeat merge of the same rows collapses rather than
    // doubling. A row written before this column carried OR-Set elements holds
    // bare descriptors; those are re-tagged here rather than discarded.
    /**
     * @return array<string, string> tag => element value
     */
    private static function decodeMergedFrom(stdClass $survivingRow): array
    {
        if (! isset($survivingRow->merged_from) || ! is_string($survivingRow->merged_from) || $survivingRow->merged_from === '') {
            return [];
        }

        $decoded = json_decode($survivingRow->merged_from, true);

        if (! is_array($decoded)) {
            return [];
        }

        $elements = [];

        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $value = isset($item['v']) && is_string($item['v'])
                ? $item['v']
                : json_encode($item, JSON_THROW_ON_ERROR);
            $tag = isset($item['tag']) && is_string($item['tag'])
                ? $item['tag']
                : substr(hash('sha256', $value), 0, 32);

            $elements[$tag] = $value;
        }

        return $elements;
    }

    private static function rowString(stdClass $row, string $field): string
    {
        return isset($row->{$field}) && is_string($row->{$field}) ? $row->{$field} : '';
    }

    // Substitution characters keep json_encode() infallible on a
    // legacy-encoded description.
    private static function coerceUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
