<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\DateFactory;
use InvalidArgumentException;
use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Core\Models\User;
use Modules\Import\Public\Services\PatternGeneralizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * @link ../../../../.docs/features/import/architecture.md#merchant-aliases
 */
final class AliasYamlImporter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PatternGeneralizer $generalizer,
        private readonly LoggerInterface $logger,
        private readonly DateFactory $dates,
    ) {}

    /**
     * @return list<CorpusEntryDto>
     */
    public function parse(string $yamlContent): array
    {
        try {
            /** @var mixed $document */
            $document = Yaml::parse($yamlContent, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $this->logger->info('AliasYamlImporter: YAML parse failed.', [
                'exception_message' => $e->getMessage(),
            ]);

            throw new InvalidArgumentException(
                'The file is not a valid YAML document.',
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new InvalidArgumentException(
                'The file could not be parsed.',
                previous: $e,
            );
        }

        if (! is_array($document) || ! isset($document['entries']) || ! is_array($document['entries'])) {
            throw new InvalidArgumentException(
                "The file is missing the top-level 'entries' list.",
            );
        }

        /** @var list<CorpusEntryDto> $entries */
        $entries = [];
        foreach ($document['entries'] as $index => $raw) {
            if (! is_array($raw)) {
                throw new InvalidArgumentException(sprintf(
                    'Entry #%d is not a mapping.',
                    is_int($index) ? $index + 1 : 0,
                ));
            }

            $pattern = isset($raw['pattern']) && is_string($raw['pattern']) ? trim($raw['pattern']) : '';
            $name = isset($raw['name']) && is_string($raw['name']) ? trim($raw['name']) : '';
            $contributor = isset($raw['contributor']) && is_string($raw['contributor']) ? $raw['contributor'] : 'user';
            $category = isset($raw['category']) && is_string($raw['category']) ? $raw['category'] : null;
            $region = isset($raw['region']) && is_string($raw['region']) ? $raw['region'] : null;

            if ($pattern === '' || $name === '') {
                throw new InvalidArgumentException(sprintf(
                    "Entry #%d is missing a required 'pattern' or 'name' field.",
                    is_int($index) ? $index + 1 : 0,
                ));
            }

            $generalized = $this->generalizer->generalize($pattern);

            $entries[] = new CorpusEntryDto(
                pattern: $pattern,
                generalizedPattern: $generalized,
                name: $name,
                category: $category,
                region: $region,
                contributor: $contributor,
            );
        }

        return $entries;
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

        $this->db->connection()->transaction(function () use ($user, $entries, $conflictResolutions, $existing, &$changed): void {
            $now = $this->dates->now()->toDateTimeString();
            $connection = $this->db->connection();

            foreach ($entries as $entry) {
                $existingRow = $existing[$entry->pattern] ?? null;

                if ($existingRow === null) {
                    $connection->table('merchant_aliases')->insert([
                        'user_id' => $user->id,
                        'pattern' => $entry->pattern,
                        'generalized_pattern' => $entry->generalizedPattern,
                        'friendly_name' => $entry->name,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $changed++;

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
            }
        });

        return $changed;
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
