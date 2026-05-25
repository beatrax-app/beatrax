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
 * Parses an uploaded YAML alias bundle, diffs it against the current
 * user's `merchant_aliases` table, and (on confirm) applies the chosen
 * conflict resolutions to the table.
 *
 * Security posture for the parse step:
 *
 *   - Parsing runs with `Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE`, which
 *     refuses YAML constructs that would try to instantiate PHP objects
 *     (the `!php/object` tag and friends). This is the single mitigation
 *     against the YAML-deserialisation-to-RCE class of attack referenced
 *     by ASVS V10 (Modules/Import/.../research/RESEARCH.md Pitfall 1).
 *   - Top-level shape is strictly validated: the document must be an
 *     array with an `entries` key holding a list. Anything else throws
 *     `InvalidArgumentException` with a user-facing message; the
 *     Livewire layer catches that and renders an inline error without
 *     ever writing to the database.
 *
 * Diff semantics. Each parsed entry is classified against the user's
 * existing aliases:
 *
 *   - `new`        — no existing alias has the same `pattern`.
 *   - `unchanged`  — an existing alias has the same `pattern` AND the
 *                    same `friendly_name`.
 *   - `conflicts`  — an existing alias has the same `pattern` but a
 *                    different `friendly_name`. The Livewire UI offers
 *                    the user a per-conflict 'keep' / 'replace' choice;
 *                    the `apply()` step branches on that choice.
 *
 * `apply()` runs the writes inside a single transaction. Unknown
 * conflict-resolution actions (anything other than 'replace') fall
 * through to the safe default 'keep', matching the threat-register
 * mitigation for T-16.1-07-08.
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
     * Parses an uploaded YAML document into a list of immutable
     * `CorpusEntryDto` values. Throws `InvalidArgumentException` on any
     * malformed input — including invalid YAML, a missing `entries`
     * key, or per-entry shape violations.
     *
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
     * Classifies each parsed entry against the user's current
     * `merchant_aliases` rows.
     *
     * Uses the raw query builder (via the injected `DatabaseManager`)
     * rather than the Eloquent Builder so the project's
     * phpstan-strict-rules `staticMethod.dynamicCall` rule doesn't
     * flag the chained methods.
     *
     * @param  list<CorpusEntryDto>  $entries
     * @return array{new: list<CorpusEntryDto>, unchanged: list<CorpusEntryDto>, conflicts: list<array{entry: CorpusEntryDto, existing_name: string}>}
     */
    public function diff(User $user, array $entries): array
    {
        $existing = $this->loadExistingByPattern($user);

        $new = [];
        $unchanged = [];
        $conflicts = [];

        foreach ($entries as $entry) {
            $existingName = $existing[$entry->pattern] ?? null;
            if ($existingName === null) {
                $new[] = $entry;

                continue;
            }
            if ($existingName === $entry->name) {
                $unchanged[] = $entry;

                continue;
            }
            $conflicts[] = [
                'entry' => $entry,
                'existing_name' => $existingName,
            ];
        }

        return [
            'new' => $new,
            'unchanged' => $unchanged,
            'conflicts' => $conflicts,
        ];
    }

    /**
     * Commits the entries to the user's `merchant_aliases` table.
     *
     * `$conflictResolutions` maps `pattern => 'keep' | 'replace'`. Any
     * unknown action falls through to 'keep' (the safe default).
     * Returns the number of rows actually changed (inserted +
     * updated). Wraps the writes in a single transaction so a mid-way
     * failure rolls back cleanly.
     *
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
                $existingName = $existing[$entry->pattern] ?? null;

                if ($existingName === null) {
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

                if ($existingName === $entry->name) {
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
     * Loads the user's current `(pattern => friendly_name)` map for
     * diff/apply. Uses the raw query builder per the project's
     * `staticMethod.dynamicCall` convention.
     *
     * @return array<string, string>
     */
    private function loadExistingByPattern(User $user): array
    {
        $rows = $this->db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $user->id)
            ->get(['pattern', 'friendly_name']);

        $map = [];
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $pattern = isset($row->pattern) && is_string($row->pattern) ? $row->pattern : '';
            $friendly = isset($row->friendly_name) && is_string($row->friendly_name) ? $row->friendly_name : '';
            if ($pattern === '') {
                continue;
            }
            $map[$pattern] = $friendly;
        }

        return $map;
    }
}
