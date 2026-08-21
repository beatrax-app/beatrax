<?php

declare(strict_types=1);

use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Core\Models\User;
use Modules\Import\Internal\Services\AliasYamlExporter;
use Modules\Import\Internal\Services\AliasYamlImporter;
use Modules\Import\Models\MerchantAlias;

// The snapshot holds still because the export query orders by id, the fixed
// fixture username keeps user_id out of the output, and symfony/yaml's
// `inline: 4` shape is documented as stable.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'yaml-roundtrip',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    // Each generalized_pattern is exactly what PatternGeneralizer produces for
    // its pattern; a mismatch would come back from the diff as a conflict.
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
