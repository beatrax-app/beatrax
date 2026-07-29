<?php

declare(strict_types=1);

namespace Modules\Import\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\DateFactory;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Import\Models\MerchantAlias;
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
            $rows = $this->db->connection()
                ->table('merchant_aliases')
                ->where('user_id', $user->id)
                ->whereIn('id', $aliasIds)
                ->orderBy('id')
                ->get(['id', 'pattern', 'generalized_pattern', 'friendly_name', 'merged_from']);

            if ($rows->count() !== $expectedCount) {
                throw new NotFoundHttpException(
                    'One or more aliases were not found for the current user.',
                );
            }

            /** @var \stdClass $survivingRow */
            $survivingRow = $rows->shift();
            $absorbed = $rows;

            $mergedAt = $this->dates->now()->toIso8601String();

            /** @var list<array<string, mixed>> $mergedFrom */
            $mergedFrom = [];
            if (isset($survivingRow->merged_from) && is_string($survivingRow->merged_from) && $survivingRow->merged_from !== '') {
                $decoded = json_decode($survivingRow->merged_from, true);
                if (is_array($decoded)) {
                    /** @var list<array<string, mixed>> $decoded */
                    $mergedFrom = $decoded;
                }
            }

            foreach ($absorbed as $row) {
                /** @var \stdClass $row */
                $rowPattern = isset($row->pattern) && is_string($row->pattern) ? $row->pattern : '';
                $rowGeneralized = isset($row->generalized_pattern) && is_string($row->generalized_pattern)
                    ? $row->generalized_pattern : '';
                $rowFriendly = isset($row->friendly_name) && is_string($row->friendly_name)
                    ? $row->friendly_name : '';
                // Raw bank-statement text occasionally carries stray
                // bytes that are not valid UTF8. json_encode() under
                // JSON_THROW_ON_ERROR aborts the whole transaction for
                // a single bad byte, so coerce each string field here.
                $mergedFrom[] = [
                    'pattern' => self::coerceUtf8($rowPattern),
                    'generalized_pattern' => self::coerceUtf8($rowGeneralized),
                    'friendly_name' => self::coerceUtf8($rowFriendly),
                    'merged_at' => $mergedAt,
                ];
            }

            $survivingId = isset($survivingRow->id) && is_numeric($survivingRow->id) ? (int) $survivingRow->id : 0;
            $now = $this->dates->now()->toDateTimeString();

            $this->db->connection()
                ->table('merchant_aliases')
                ->where('user_id', $user->id)
                ->where('id', $survivingId)
                ->update([
                    'friendly_name' => $friendlyName,
                    'generalized_pattern' => $generalizedPattern,
                    'merged_from' => json_encode($mergedFrom, JSON_THROW_ON_ERROR),
                    'updated_at' => $now,
                ]);

            $absorbedIds = [];
            foreach ($absorbed as $row) {
                /** @var \stdClass $row */
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

            /** @var MerchantAlias|null $refreshed */
            $refreshed = MerchantAlias::query()
                ->where('user_id', $user->id)
                ->where('id', $survivingId)
                ->first();
            if ($refreshed === null) {
                throw new NotFoundHttpException(
                    'The surviving alias row vanished mid-transaction.',
                );
            }

            return $refreshed;
        });

        return $surviving;
    }

    // Replaces invalid bytes with the Unicode substitution character so
    // json_encode() stays infallible for legacy-encoded descriptions.
    private static function coerceUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
}
