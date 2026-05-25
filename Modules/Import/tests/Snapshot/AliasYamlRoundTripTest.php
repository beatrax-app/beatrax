<?php

declare(strict_types=1);

use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Core\Models\User;
use Modules\Import\Internal\Services\AliasYamlExporter;
use Modules\Import\Internal\Services\AliasYamlImporter;
use Modules\Import\Models\MerchantAlias;

/*
 * Round-trip snapshot for the YAML exporter / importer pair.
 *
 * Three properties are locked here:
 *
 *   1. The serialised YAML matches a committed snapshot — accidental
 *      drift in schema, ordering, or formatting fails CI before the
 *      file shape leaks into a downstream tool.
 *   2. The exported YAML parses cleanly back into a list of
 *      CorpusEntryDto values carrying the same pattern + name shape.
 *   3. Diffing the parsed entries against the user's current
 *      merchant_aliases classifies all of them as `unchanged`. Export
 *      → re-import must never appear as new rows or as conflicts.
 *
 * The export is deterministic because:
 *   - the query orders by id (stable across runs),
 *   - the user is created with a fixed username so the user_id is
 *     not surfaced in the output,
 *   - the exported payload uses symfony/yaml's `inline: 4` shape
 *     which is documented as stable.
 */

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'yaml-roundtrip',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    // Each row's `generalized_pattern` matches what
    // `PatternGeneralizer::generalize($pattern)` produces so the
    // round-trip diff classifies every re-imported entry as
    // `unchanged`. A mismatched generalized_pattern would surface as
    // a `conflict` row under the diff contract.
    foreach ([
        ['BCK*SHELL PIETER NIEUW *0123', 'bck*shell pieter nieuw', 'Shell Pieter'],
        ['ALBERT HEIJN 1245 T07438', 'albert heijn', 'Albert Heijn'],
        ['SPOTIFY P0H8ABC', 'spotify p0h8abc', 'Spotify'],
    ] as [$pattern, $generalized, $friendly]) {
        MerchantAlias::create([
            'user_id' => $this->user->id,
            'pattern' => $pattern,
            'generalized_pattern' => $generalized,
            'friendly_name' => $friendly,
        ]);
    }
});

it('serialises the user aliases to a snapshot-matched YAML document', function (): void {
    /** @var AliasYamlExporter $exporter */
    $exporter = $this->app->make(AliasYamlExporter::class);
    $yaml = $exporter->export($this->user);

    expect($yaml)->toMatchSnapshot();
});

it('parses the exported YAML back into CorpusEntryDto values with the same patterns and names', function (): void {
    /** @var AliasYamlExporter $exporter */
    $exporter = $this->app->make(AliasYamlExporter::class);
    /** @var AliasYamlImporter $importer */
    $importer = $this->app->make(AliasYamlImporter::class);

    $yaml = $exporter->export($this->user);
    $entries = $importer->parse($yaml);

    expect($entries)->toHaveCount(3);
    expect($entries[0])->toBeInstanceOf(CorpusEntryDto::class);

    $patterns = array_map(static fn (CorpusEntryDto $e): string => $e->pattern, $entries);
    $names = array_map(static fn (CorpusEntryDto $e): string => $e->name, $entries);

    expect($patterns)->toContain('BCK*SHELL PIETER NIEUW *0123');
    expect($patterns)->toContain('ALBERT HEIJN 1245 T07438');
    expect($patterns)->toContain('SPOTIFY P0H8ABC');
    expect($names)->toContain('Shell Pieter');
    expect($names)->toContain('Albert Heijn');
    expect($names)->toContain('Spotify');
});

it('classifies every re-imported entry as unchanged when diffed against the original aliases', function (): void {
    /** @var AliasYamlExporter $exporter */
    $exporter = $this->app->make(AliasYamlExporter::class);
    /** @var AliasYamlImporter $importer */
    $importer = $this->app->make(AliasYamlImporter::class);

    $yaml = $exporter->export($this->user);
    $entries = $importer->parse($yaml);
    $diff = $importer->diff($this->user, $entries);

    expect($diff['new'])->toHaveCount(0);
    expect($diff['conflicts'])->toHaveCount(0);
    expect($diff['unchanged'])->toHaveCount(3);
});
