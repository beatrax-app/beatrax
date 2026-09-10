<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\DateFactory;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Counterparties\Internal\Enums\CounterpartyMetadataKey;
use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;
use Modules\Counterparties\Public\Contracts\MergesCounterparties;
use Modules\Counterparties\Public\Dto\CounterpartyMergeDto;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

/**
 * @link ../../../../.docs/features/counterparties/retention.md#what-still-deletes-a-counterparty
 */
final readonly class MergeCounterparties implements MergesCounterparties
{
    use CoercesScalars;

    private const int BATCH = 500;

    private const string SOURCE_MAP_ENTITY = 'counterparty';

    public function __construct(
        private DatabaseManager $db,
        private CounterpartySlugResolver $slugResolver,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private DateFactory $dates,
    ) {}

    /**
     * @param  list<string>  $formerNames
     */
    public function fold(User $user, array $formerNames, string $survivingName): CounterpartyMergeDto
    {
        $userId = $user->id;

        // Every name is resolved to its row before anything is written: the
        // survivor's rename moves a slug, and a name resolved after it would
        // walk onto a different row.
        $byName = $this->rowsOwnedByNames($userId, [...$formerNames, $survivingName]);

        if ($byName === []) {
            return CounterpartyMergeDto::nothingToFold();
        }

        $survivor = $byName[$survivingName] ?? array_values($byName)[0];
        $survivorId = self::toInt($survivor->id);

        /** @var list<EntityMutated> $events */
        $events = [];
        /** @var list<array{name: string, slug: string, moved: int}> $absorbed */
        $absorbed = [];
        $moved = 0;

        foreach ($this->absorbedRows($byName, $survivorId) as $row) {
            $carried = $this->foldRow($userId, self::toInt($row->id), $survivorId, $events);
            $absorbed[] = [
                'name' => $this->displayNameOf($row, $userId),
                'slug' => self::toString($row->slug ?? null),
                'moved' => $carried,
            ];
            $moved += $carried;
        }

        $this->rewriteSurvivor($userId, $survivor, $survivingName, $absorbed, $events);

        return new CounterpartyMergeDto($survivorId, $absorbed, $moved, $events);
    }

    /**
     * @param  list<string>  $names
     * @return array<string, stdClass>
     */
    private function rowsOwnedByNames(int $userId, array $names): array
    {
        $rows = [];

        foreach ($names as $name) {
            if ($name === '' || array_key_exists($name, $rows)) {
                continue;
            }

            $row = $this->rowOwning($userId, $name);

            if ($row !== null) {
                $rows[$name] = $row;
            }
        }

        return $rows;
    }

    // resolveUnique() answers with the slug a name already owns where one holds
    // it and a free slug otherwise, so a lookup on what it returns is the
    // inverse of the walk an import takes to reach the same row.
    private function rowOwning(int $userId, string $name): ?stdClass
    {
        $row = $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->where('slug', $this->slugResolver->resolveUnique($userId, $name))
            ->first(['id', 'slug', 'type', 'display_name', 'metadata']);

        return $row instanceof stdClass ? $row : null;
    }

    // Two of the merged names can own one row — the surviving alias's own name
    // is usually what the reader keeps — so the absorbed set is taken by id.
    /**
     * @param  array<string, stdClass>  $byName
     * @return list<stdClass>
     */
    private function absorbedRows(array $byName, int $survivorId): array
    {
        $rows = [];
        $seen = [$survivorId => true];

        foreach ($byName as $row) {
            $id = self::toInt($row->id);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<EntityMutated>  $events
     */
    private function foldRow(int $userId, int $absorbedId, int $survivorId, array &$events): int
    {
        $moved = $this->repointTransactions($userId, $absorbedId, $survivorId, $events);

        $this->repointSuppressionRules($userId, $absorbedId, $survivorId, $events);
        $this->repointSourceMap($userId, $absorbedId, $survivorId, $events);
        $this->removeRow($userId, $absorbedId, $events);

        return $moved;
    }

    // transactions.counterparty_id carries no foreign key, deliberately, so
    // nothing moves these and nothing warns when they are left behind. The
    // reconciled-row freeze is a rule about a row a reader NAMES; refusing here
    // would strand one on a counterparty this call is about to remove.
    /**
     * @param  list<EntityMutated>  $events
     */
    private function repointTransactions(int $userId, int $absorbedId, int $survivorId, array &$events): int
    {
        $moved = 0;
        $after = 0;

        while (true) {
            $ids = $this->idsPointingAt('transactions', 'counterparty_id', $userId, $absorbedId, $after);

            if ($ids === []) {
                return $moved;
            }

            $after = $ids[array_key_last($ids)];

            $moved += $this->db->connection()
                ->table('transactions')
                ->where('user_id', $userId)
                ->whereIn('id', $ids)
                ->update(['counterparty_id' => $survivorId]);

            $this->announce($events, 'transactions', $ids, $userId, ['counterparty_id' => $survivorId]);
        }
    }

    // The foreign key here is nullOnDelete, and a suppression rule holding a
    // null counterparty_id matches every merchant: letting the removal null it
    // would widen one merchant's mute over the whole ledger in silence.
    /**
     * @param  list<EntityMutated>  $events
     */
    private function repointSuppressionRules(int $userId, int $absorbedId, int $survivorId, array &$events): void
    {
        $ids = $this->idsPointingAt('anomaly_suppression_rules', 'counterparty_id', $userId, $absorbedId, 0);

        if ($ids === []) {
            return;
        }

        $this->db->connection()
            ->table('anomaly_suppression_rules')
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->update(['counterparty_id' => $survivorId]);

        $this->announce($events, 'anomaly_suppression_rules', $ids, $userId, ['counterparty_id' => $survivorId]);
    }

    // SourceMapWriter reads an existing mapping back and returns its id rather
    // than resolving again, so a row left naming the absorbed counterparty
    // hands a deleted id to the next re-import of the same export.
    /**
     * @param  list<EntityMutated>  $events
     */
    private function repointSourceMap(int $userId, int $absorbedId, int $survivorId, array &$events): void
    {
        $ids = $this->sourceMapIdsNaming($userId, $absorbedId);

        if ($ids === []) {
            return;
        }

        $this->db->connection()
            ->table('migration_source_map')
            ->where('user_id', $userId)
            ->whereIn('id', $ids)
            ->update(['beatrax_id' => $survivorId]);

        $this->announce($events, 'migration_source_map', $ids, $userId, ['beatrax_id' => $survivorId]);
    }

    /**
     * @param  list<EntityMutated>  $events
     */
    private function removeRow(int $userId, int $absorbedId, array &$events): void
    {
        $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->where('id', $absorbedId)
            ->delete();

        $events[] = new EntityMutated('counterparties', $absorbedId, $userId, 'delete');
    }

    // The merged name is what the next import slugs, so the surviving row takes
    // it: left on its old name, the fold is undone by the very import it exists
    // to keep on one row.
    /**
     * @param  list<array{name: string, slug: string, moved: int}>  $absorbed
     * @param  list<EntityMutated>  $events
     */
    private function rewriteSurvivor(int $userId, stdClass $survivor, string $survivingName, array $absorbed, array &$events): void
    {
        $survivorId = self::toInt($survivor->id);
        $renamed = $this->renameFor($userId, $survivor, $survivingName, $survivorId);

        // One alias of the set having named a counterparty is enough for the
        // rename to matter: the row is not absorbing anything, but its slug
        // still has to follow the merged name or the next import forks it.
        if ($renamed === [] && $absorbed === []) {
            return;
        }

        $metadata = $absorbed === [] ? null : $this->provenanceFor($survivor, $absorbed);
        $columns = $this->codec->encryptAttrs('counterparties', $renamed, $userId, ($this->session)());

        if ($metadata !== null) {
            $columns['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);
        }

        $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->where('id', $survivorId)
            ->update($columns);

        // Plaintext, as the resolver's own announcements are: OpLogWriter seals
        // the sensitive columns again under the current epoch, so handing it the
        // stored ciphertext would encrypt it twice.
        $events[] = new EntityMutated(
            table: 'counterparties',
            pk: $survivorId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: $metadata === null ? $renamed : [...$renamed, 'metadata' => $metadata],
        );
    }

    /**
     * @return array<string, string>
     */
    private function renameFor(int $userId, stdClass $survivor, string $survivingName, int $survivorId): array
    {
        if ($this->displayNameOf($survivor, $userId) === $survivingName) {
            return [];
        }

        $renamed = [
            'display_name' => $survivingName,
            'slug' => $this->slugResolver->resolveUnique($userId, $survivingName, $survivorId),
        ];

        // merchant_name is the resolved alias name, and on a merchant row it is
        // the same string the reader has just replaced.
        if (self::toString($survivor->type ?? null) === CounterpartyType::Merchant->value) {
            $renamed['merchant_name'] = $survivingName;
        }

        return $renamed;
    }

    // A merge is destructive and has no undo, so what it absorbed is written
    // onto the row that survived it, the way merchant_aliases.merged_from
    // records the same event one table over.
    /**
     * @param  list<array{name: string, slug: string, moved: int}>  $absorbed
     * @return array<string, mixed>
     */
    private function provenanceFor(stdClass $survivor, array $absorbed): array
    {
        $metadata = $this->decodeMetadata($survivor);
        $key = CounterpartyMetadataKey::MergedFrom->value;
        $existing = is_array($metadata[$key] ?? null) ? array_values($metadata[$key]) : [];
        $mergedAt = $this->dates->now()->toIso8601String();

        foreach ($absorbed as $row) {
            $existing[] = [...$row, 'merged_at' => $mergedAt];
        }

        $metadata[$key] = $existing;

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(stdClass $survivor): array
    {
        $stored = self::toString($survivor->metadata ?? null);
        $decoded = $stored === '' ? [] : json_decode($stored, true);

        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function displayNameOf(stdClass $row, int $userId): string
    {
        $stored = self::toString($row->display_name ?? null);

        if ($stored === '') {
            return '';
        }

        return $this->codec->decryptValue('counterparties', 'display_name', $stored, $userId, ($this->session)())['value'];
    }

    /**
     * @return list<int>
     */
    private function idsPointingAt(string $table, string $column, int $userId, int $referentId, int $after): array
    {
        $rows = $this->db->connection()
            ->table($table)
            ->where('user_id', $userId)
            ->where($column, $referentId)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id']);

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = self::toInt($row->id);
        }

        return $ids;
    }

    // beatrax_id is only a counterparty id on the rows that say so: an account
    // mapping carrying the same number is a different entity entirely.
    /**
     * @return list<int>
     */
    private function sourceMapIdsNaming(int $userId, int $absorbedId): array
    {
        $rows = $this->db->connection()
            ->table('migration_source_map')
            ->where('user_id', $userId)
            ->where('beatrax_entity_type', self::SOURCE_MAP_ENTITY)
            ->where('beatrax_id', $absorbedId)
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id']);

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = self::toInt($row->id);
        }

        return $ids;
    }

    /**
     * @param  list<EntityMutated>  $events
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $dirtyFields
     */
    private function announce(array &$events, string $table, array $ids, int $userId, array $dirtyFields): void
    {
        foreach ($ids as $id) {
            $events[] = new EntityMutated($table, $id, $userId, 'edit', $dirtyFields);
        }
    }
}
