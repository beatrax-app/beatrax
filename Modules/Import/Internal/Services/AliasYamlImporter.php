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
            /** @var mixed $document */
            $document = Yaml::parse($yamlContent, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $e) {
            $this->logger->info('AliasYamlImporter: YAML parse failed.', [
                'exception_message' => $e->getMessage(),
            ]);

            throw new InvalidArgumentException('The file is not a valid YAML document.', previous: $e);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('The file could not be parsed.', previous: $e);
        }

        if (! is_array($document) || ! isset($document['entries']) || ! is_array($document['entries'])) {
            throw new InvalidArgumentException("The file is missing the top-level 'entries' list.");
        }

        return $document['entries'];
    }

    private function mapEntry(mixed $raw, int|string $index): CorpusEntryDto
    {
        $position = is_int($index) ? $index + 1 : 0;
        if (! is_array($raw)) {
            throw new InvalidArgumentException(sprintf('Entry #%d is not a mapping.', $position));
        }

        $pattern = trim(self::stringField($raw, 'pattern') ?? '');
        $name = trim(self::stringField($raw, 'name') ?? '');
        if ($pattern === '' || $name === '') {
            throw new InvalidArgumentException(sprintf(
                "Entry #%d is missing a required 'pattern' or 'name' field.",
                $position,
            ));
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
