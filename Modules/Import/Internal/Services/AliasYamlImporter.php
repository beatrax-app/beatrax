<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\DateFactory;
use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Core\Models\User;
use Modules\Import\Internal\Enums\AliasFileRejection;
use Modules\Import\Internal\Exceptions\AliasFileRejectedException;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Import\Public\Services\PatternGeneralizer;
use Modules\Sync\Public\Events\EntityMutated;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final readonly class AliasYamlImporter
{
    public function __construct(
        private DatabaseManager $db,
        private PatternGeneralizer $generalizer,
        private LoggerInterface $logger,
        private DateFactory $dates,
        private Dispatcher $events,
        private MerchantNameResolver $resolver,
    ) {}

    /**
     * @return list<CorpusEntryDto>
     *
     * @throws AliasFileRejectedException when the file is not an alias list this build can read
     */
    public function parse(string $yamlContent): array
    {
        $entries = [];
        foreach ($this->decodeEntries($yamlContent) as $index => $raw) {
            $entries[] = $this->mapEntry($raw, $index);
        }

        return $entries;
    }

    /**
     * @return array<array-key, mixed> the raw `entries` list
     */
    private function decodeEntries(string $yamlContent): array
    {
        try {
            $document = Yaml::parse($yamlContent, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $this->logger->info('AliasYamlImporter: YAML parse failed.', [
                'exception_message' => $e->getMessage(),
            ]);

            throw AliasFileRejectedException::file(AliasFileRejection::NotYaml, $e);
        } catch (Throwable $e) {
            throw AliasFileRejectedException::file(AliasFileRejection::UnreadableAsYaml, $e);
        }

        if (! is_array($document) || ! isset($document['entries']) || ! is_array($document['entries'])) {
            throw AliasFileRejectedException::file(AliasFileRejection::NoEntriesList);
        }

        return $document['entries'];
    }

    private function mapEntry(mixed $raw, int|string $index): CorpusEntryDto
    {
        $position = is_int($index) ? $index + 1 : 0;
        if (! is_array($raw)) {
            throw AliasFileRejectedException::entry(AliasFileRejection::EntryIsNotAMapping, $position);
        }

        $pattern = trim(self::stringField($raw, 'pattern') ?? '');
        $name = trim(self::stringField($raw, 'name') ?? '');
        if ($pattern === '' || $name === '') {
            throw AliasFileRejectedException::entry(AliasFileRejection::EntryIsMissingAField, $position);
        }

        return new CorpusEntryDto(
            pattern: $pattern,
            generalizedPattern: $this->generalizer->generalize($pattern),
            name: $name,
            category: self::stringField($raw, 'category'),
            region: self::stringField($raw, 'region'),
            contributor: self::stringField($raw, 'contributor') ?? 'user',
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private static function stringField(array $raw, string $key): ?string
    {
        return isset($raw[$key]) && is_string($raw[$key]) ? $raw[$key] : null;
    }

    /**
     * @param  list<CorpusEntryDto>  $entries
     * @return array{new: list<CorpusEntryDto>, unchanged: list<CorpusEntryDto>, conflicts: list<array{entry: CorpusEntryDto, existing_name: string, existing_generalized_pattern: string}>}
     */
    public function diff(User $user, array $entries): array
    {
        $existing = $this->loadExistingByPattern($user);

        $new = [];
        $unchanged = [];
        $conflicts = [];

        foreach ($entries as $entry) {
            $existingRow = $existing[$entry->pattern] ?? null;
            if ($existingRow === null) {
                $new[] = $entry;

                continue;
            }
            if (
                $existingRow['friendly_name'] === $entry->name
                && $existingRow['generalized_pattern'] === $entry->generalizedPattern
            ) {
                $unchanged[] = $entry;

                continue;
            }
            $conflicts[] = [
                'entry' => $entry,
                'existing_name' => $existingRow['friendly_name'],
                'existing_generalized_pattern' => $existingRow['generalized_pattern'],
            ];
        }

        return [
            'new' => $new,
            'unchanged' => $unchanged,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param  list<CorpusEntryDto>  $entries
     * @param  array<string, string>  $conflictResolutions
     */
    public function apply(User $user, array $entries, array $conflictResolutions): int
    {
        $existing = $this->loadExistingByPattern($user);
        $changed = 0;
        /** @var list<EntityMutated> $captured */
        $captured = [];

        $this->db->connection()->transaction(function () use ($user, $entries, $conflictResolutions, $existing, &$changed, &$captured): void {
            [$changed, $captured] = $this->writeEntries($user, $entries, $conflictResolutions, $existing);
        });

        // Only once the rows are committed. Dispatched inside, a rollback left
        // the op log carrying an alias no local row matched, and the paired
        // device created it anyway.
        foreach ($captured as $event) {
            $this->events->dispatch($event);
        }

        // A bulk import replaces whole patterns at once, so a memo not told
        // about it answers the rest of this request out of the list the file
        // just superseded.
        $this->resolver->forget($user->id);

        return $changed;
    }

    /**
     * @param  list<CorpusEntryDto>  $entries
     * @param  array<string, string>  $conflictResolutions
     * @param  array<string, array{friendly_name: string, generalized_pattern: string}>  $existing
     * @return array{0: int, 1: list<EntityMutated>} rows written, and the ops for the ones that
     *                                               survive the commit
     */
    private function writeEntries(User $user, array $entries, array $conflictResolutions, array $existing): array
    {
        $changed = 0;
        $captured = [];
        $now = $this->dates->now()->toDateTimeString();
        $connection = $this->db->connection();

        foreach ($entries as $entry) {
            $existingRow = $existing[$entry->pattern] ?? null;

            if ($existingRow === null) {
                $aliasId = $connection->table('merchant_aliases')->insertGetId([
                    'user_id' => $user->id,
                    'pattern' => $entry->pattern,
                    'generalized_pattern' => $entry->generalizedPattern,
                    'friendly_name' => $entry->name,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $changed++;

                // The user's own work, uploaded on the settings page, so
                // it travels with them.
                $captured[] = new EntityMutated(
                    table: 'merchant_aliases',
                    pk: $aliasId,
                    userId: $user->id,
                    mutationType: 'create',
                    dirtyFields: [
                        'user_id' => $user->id,
                        'pattern' => $entry->pattern,
                        'generalized_pattern' => $entry->generalizedPattern,
                        'friendly_name' => $entry->name,
                    ],
                );

                continue;
            }

            if (
                $existingRow['friendly_name'] === $entry->name
                && $existingRow['generalized_pattern'] === $entry->generalizedPattern
            ) {
                continue;
            }

            $action = $conflictResolutions[$entry->pattern] ?? 'keep';
            if ($action !== 'replace') {
                continue;
            }

            $connection->table('merchant_aliases')
                ->where('user_id', $user->id)
                ->where('pattern', $entry->pattern)
                ->update([
                    'generalized_pattern' => $entry->generalizedPattern,
                    'friendly_name' => $entry->name,
                    'updated_at' => $now,
                ]);
            $changed++;

            // The replace branch captured nothing, so an import that only
            // resolved conflicts changed this device and no other. Keyed
            // by pattern, so the pk has to be read back rather than
            // assumed from the insert above.
            $replacedId = $connection->table('merchant_aliases')
                ->where('user_id', $user->id)
                ->where('pattern', $entry->pattern)
                ->value('id');

            if (is_numeric($replacedId)) {
                $captured[] = new EntityMutated(
                    table: 'merchant_aliases',
                    pk: (int) $replacedId,
                    userId: $user->id,
                    mutationType: 'edit',
                    dirtyFields: [
                        'generalized_pattern' => $entry->generalizedPattern,
                        'friendly_name' => $entry->name,
                    ],
                );
            }
        }

        return [$changed, $captured];
    }

    /**
     * @return array<string, array{friendly_name: string, generalized_pattern: string}>
     */
    private function loadExistingByPattern(User $user): array
    {
        $rows = $this->db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $user->id)
            ->get(['pattern', 'friendly_name', 'generalized_pattern']);

        $map = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $pattern = isset($row->pattern) && is_string($row->pattern) ? $row->pattern : '';
            $friendly = isset($row->friendly_name) && is_string($row->friendly_name) ? $row->friendly_name : '';
            $generalized = isset($row->generalized_pattern) && is_string($row->generalized_pattern)
                ? $row->generalized_pattern
                : '';
            if ($pattern === '') {
                continue;
            }
            $map[$pattern] = [
                'friendly_name' => $friendly,
                'generalized_pattern' => $generalized,
            ];
        }

        return $map;
    }
}
