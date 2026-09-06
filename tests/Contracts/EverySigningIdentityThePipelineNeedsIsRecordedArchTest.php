<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// A workflow belongs to the release pipeline if it builds an installable
// bundle, uploads to a release page, or publishes the tree the mobile builder
// reads. Detected from what the workflow does rather than from a list of
// names, so a new one is in scope the day it is written rather than the day
// somebody remembers this file.
const SIR_PIPELINE_MARKS = '/native:build|native:package|mobile:package-|softprops\/action-gh-release|BIFROST_BUILD_REPO/';

const SIR_REGISTER = 'runbooks/signing-identities.md';

// The two states that are not a date, plus the one placeholder the column
// accepts. Free prose there is how a Trusted Signing variable once came to
// hold the literal text "(pending — set after cert profile is created)".
const SIR_VERDICTS = ['Never', 'Per job', 'Unread'];

function sirDocsDirectory(): string
{
    foreach (['.docs', '../.docs'] as $candidate) {
        $path = base_path($candidate);

        if (is_dir($path)) {
            return $path;
        }
    }

    return '';
}

function sirWorkflowDirectory(): string
{
    foreach (['.github/workflows', '../.github/workflows'] as $candidate) {
        $path = base_path($candidate);

        if (is_dir($path)) {
            return $path;
        }
    }

    return '';
}

/**
 * Whether a workflow belongs to the release pipeline. Comments come off first:
 * ci.yml names native:build in one explaining which PHP the release runs on,
 * and it signs nothing. Named and taking a source string so the control below
 * drives the same reader the walk drives.
 */
function sirIsPipelineWorkflow(string $source): bool
{
    return PatternScan::matches(SIR_PIPELINE_MARKS, PatternScan::replace('/^\s*#.*$/m', '', $source));
}

/** @return list<string> every workflow file, in either spelling of the extension */
function sirWorkflowFiles(): array
{
    $files = [];

    foreach (['/*.yml', '/*.yaml'] as $pattern) {
        foreach ((array) glob(sirWorkflowDirectory().$pattern) as $file) {
            $files[] = (string) $file;
        }
    }

    sort($files);

    return $files;
}

/**
 * Every `secrets.*` and `vars.*` name the release-pipeline workflows read.
 *
 * @return list<string>
 */
function sirCredentialsThePipelineReads(): array
{
    $names = [];

    foreach (sirWorkflowFiles() as $file) {
        $source = (string) file_get_contents($file);

        if (! sirIsPipelineWorkflow($source)) {
            continue;
        }

        foreach (PatternScan::sets('/(?:secrets|vars)\.([A-Z][A-Z0-9_]+)/', $source) as $set) {
            $names[] = $set[1];
        }
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

/**
 * The register's rows, as identity / held-as names / expiry / source.
 *
 * @return list<array{identity: string, held: list<string>, expires: string, source: string}>
 */
function sirRegisterRows(): array
{
    $page = sirDocsDirectory().'/'.SIR_REGISTER;

    if (! is_file($page)) {
        return [];
    }

    // Only the table under "The register" — the page carries prose tables
    // nowhere else today, and pinning the section keeps it that way.
    $section = PatternScan::first('/\n## The register\n(.*?)\n## /s', (string) file_get_contents($page));

    $rows = [];

    foreach (explode("\n", $section[1] ?? '') as $line) {
        if (! str_starts_with($line, '| ') || str_contains($line, '|---')) {
            continue;
        }

        // A cell may hold an escaped pipe — a shell one-liner in the source
        // column does — and splitting on those would shear the row.
        $cells = array_map(trim(...), PatternScan::split('/(?<!\\\\)\|/', $line));

        if (count($cells) < 6 || $cells[1] === 'Identity') {
            continue;
        }

        $held = array_map(
            static fn (array $set): string => $set[1],
            PatternScan::sets('/`([A-Z][A-Z0-9_]+)`/', $cells[3]),
        );

        $rows[] = [
            'identity' => $cells[1],
            'held' => array_values($held),
            'expires' => $cells[4],
            'source' => $cells[5],
        ];
    }

    return $rows;
}

/**
 * @return list<string>
 */
function sirRecordedCredentials(): array
{
    $names = [];

    foreach (sirRegisterRows() as $row) {
        foreach ($row['held'] as $name) {
            $names[] = $name;
        }
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

it('finds a register and a pipeline to compare it against', function (): void {
    // Fifteen workflows sit under .github/workflows today. A glob that came
    // back short would report the credentials it never opened as unrecorded,
    // or — reading none of them — as none at all.
    expect(count(sirWorkflowFiles()))->toBeGreaterThan(
        5,
        'Almost no workflow file was found, so the pipeline half of this comparison is missing.'
    );

    expect(sirDocsDirectory())->not->toBe('', '.docs was not found from either composer root')
        ->and(sirWorkflowDirectory())->not->toBe('', '.github/workflows was not found from either composer root')
        ->and(sirRegisterRows())->not->toBe([], SIR_REGISTER.' has no rows this guard can read.')
        ->and(sirCredentialsThePipelineReads())->not->toBe([], implode("\n", [
            'No workflow reads a secret or a variable, which cannot be true while',
            'the release still signs anything. The pipeline detector has stopped',
            'matching — check it before trusting anything below.',
        ]));
});

it('records every credential the release pipeline asks for', function (): void {
    $unrecorded = array_values(array_diff(sirCredentialsThePipelineReads(), sirRecordedCredentials()));

    expect($unrecorded)->toBe([], implode("\n", [
        'A release-pipeline workflow reads these, and no row in',
        SIR_REGISTER.' says what they are or when they lapse:',
        ...array_map(static fn (string $name): string => '  - '.$name, $unrecorded),
        '',
        'An expiry nobody is watching stops releases, and every way this',
        'pipeline loses an identity is silent: electron-builder drops',
        'azureSignOptions when an Azure value is empty and ships an unsigned',
        'installer from a green build. Add a row with the expiry and the command',
        'that reads it -- Unread is allowed for a date nobody has looked up yet,',
        'and is the only placeholder the column takes.',
    ]));
});

it('claims no credential the release pipeline stopped asking for', function (): void {
    $phantom = array_values(array_diff(sirRecordedCredentials(), sirCredentialsThePipelineReads()));

    expect($phantom)->toBe([], implode("\n", [
        SIR_REGISTER.' names these, and no release-pipeline workflow reads them:',
        ...array_map(static fn (string $name): string => '  - '.$name, $phantom),
        '',
        'Half of what the requirement asks is that the recorded account match',
        'what the pipeline requires. A row nothing reads is an identity somebody',
        'will keep renewing for a build that stopped needing it, and it makes',
        'the table read as complete while the check covers less than it looks.',
    ]));
});

it('gives every identity an expiry in a shape that can be read', function (): void {
    $malformed = [];

    foreach (sirRegisterRows() as $row) {
        $expires = $row['expires'];

        if (PatternScan::matches('/^\d{4}-\d{2}-\d{2}$/', $expires) || in_array($expires, SIR_VERDICTS, true)) {
            if ($row['source'] !== '') {
                continue;
            }

            $malformed[] = $row['identity'].': an expiry with no source';

            continue;
        }

        $malformed[] = $row['identity'].': "'.$expires.'"';
    }

    expect($malformed)->toBe([], implode("\n", [
        'These rows do not state an expiry a reader can act on:',
        ...array_map(static fn (string $line): string => '  - '.$line, $malformed),
        '',
        'The column takes an ISO date, or one of: '.implode(', ', SIR_VERDICTS).'.',
        'Nothing else, because free prose there is how a Trusted Signing variable',
        'came to hold "(pending -- set after cert profile is created)" and every',
        'check confirmed the credential existed rather than that it resolved.',
        'Every row also names where its date is read from, so the next reader',
        'runs a command instead of going looking.',
    ]));
});

it('is the only page in the tree carrying these dates', function (): void {
    $dates = [];

    foreach (sirRegisterRows() as $row) {
        if (PatternScan::matches('/^\d{4}-\d{2}-\d{2}$/', $row['expires'])) {
            $dates[$row['expires']] = $row['identity'];
        }
    }

    expect($dates)->not->toBe([], 'The register records no dated expiry at all, which cannot be right.');

    $copies = [];
    $register = sirDocsDirectory().'/'.SIR_REGISTER;
    $pages = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(sirDocsDirectory()));

    foreach ($pages as $page) {
        $path = (string) $page;

        if (! str_ends_with($path, '.md') || realpath($path) === realpath($register)) {
            continue;
        }

        $body = (string) file_get_contents($path);

        foreach ($dates as $date => $identity) {
            if (str_contains($body, $date)) {
                $copies[] = substr($path, strlen(sirDocsDirectory()) + 1).' repeats '.$date.' ('.$identity.')';
            }
        }
    }

    sort($copies);

    expect($copies)->toBe([], implode("\n", [
        'A second copy of an expiry date:',
        ...array_map(static fn (string $line): string => '  - '.$line, $copies),
        '',
        'The inventory used to live on three pages, and that is how five',
        'identities ended up recorded on none of them. One register, and every',
        'other page links to it -- a date written twice is a date that will be',
        'renewed in one place.',
    ]));
});

// The pipeline detector is where the whole comparison gets its subject, and one
// that matched nothing would report the register as claiming credentials no
// workflow reads — every row at once, which reads as a broken register rather
// than as a broken guard.
it('reads a signing workflow, and not a comment naming one', function (): void {
    $signs = <<<'YAML'
        jobs:
          release:
            steps:
              - run: php artisan native:build
              - uses: softprops/action-gh-release@v2
        YAML;

    $mentionsOnly = <<<'YAML'
        jobs:
          test:
            # The release runs native:build on this same PHP, so pin it here too.
            steps:
              - run: vendor/bin/pest
        YAML;

    expect(sirIsPipelineWorkflow($signs))->toBeTrue();
    expect(sirIsPipelineWorkflow($mentionsOnly))->toBeFalse();
});
