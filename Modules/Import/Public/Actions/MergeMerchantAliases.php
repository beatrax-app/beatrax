<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\DateFactory;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Import\Models\MerchantAlias;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/import/architecture.md#merchant-aliases
 */
final class MergeMerchantAliases
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly DateFactory $dates,
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

        /** @var MerchantAlias $surviving */
        $surviving = $this->db->connection()->transaction(function () use (
            $user,
            $aliasIds,
            $expectedCount,
            $friendlyName,
            $generalizedPattern,
        ): MerchantAlias {
            $rows = $this->loadAliasRows($user, $aliasIds, $expectedCount);

            /** @var stdClass $survivingRow */
            $survivingRow = $rows->shift();
            $absorbed = $rows;
            $survivingId = isset($survivingRow->id) && is_numeric($survivingRow->id) ? (int) $survivingRow->id : 0;

            $this->rewriteSurviving(
                $user,
                $survivingId,
                $friendlyName,
                $generalizedPattern,
                $this->buildMergedFrom($survivingRow, $absorbed),
            );
            $this->deleteAbsorbed($user, $absorbed);

            return $this->reloadSurviving($user, $survivingId);
        });

        return $surviving;
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

    /**
     * @param  Collection<int, stdClass>  $absorbed
     * @return list<array<string, mixed>>
     */
    private function buildMergedFrom(stdClass $survivingRow, Collection $absorbed): array
    {
        $mergedFrom = self::decodeMergedFrom($survivingRow);
        $mergedAt = $this->dates->now()->toIso8601String();

        foreach ($absorbed as $row) {
            // Raw statement text carries the occasional non-UTF8 byte, and
            // json_encode() under JSON_THROW_ON_ERROR aborts the whole
            // transaction for one of them.
            $mergedFrom[] = [
                'pattern' => self::coerceUtf8(self::rowString($row, 'pattern')),
                'generalized_pattern' => self::coerceUtf8(self::rowString($row, 'generalized_pattern')),
                'friendly_name' => self::coerceUtf8(self::rowString($row, 'friendly_name')),
                'merged_at' => $mergedAt,
            ];
        }

        return $mergedFrom;
    }

    /**
     * @param  list<array<string, mixed>>  $mergedFrom
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
     */
    private function deleteAbsorbed(User $user, Collection $absorbed): void
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

    /**
     * @return list<array<string, mixed>>
     */
    private static function decodeMergedFrom(stdClass $survivingRow): array
    {
        if (! isset($survivingRow->merged_from) || ! is_string($survivingRow->merged_from) || $survivingRow->merged_from === '') {
            return [];
        }

        $decoded = json_decode($survivingRow->merged_from, true);

        /** @var list<array<string, mixed>> */
        return is_array($decoded) ? $decoded : [];
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
