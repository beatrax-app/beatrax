<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The verify recipe named the stable set alone, and a preview page carries the
// other one: every platform leg copies its manifest to `beta*.yml` whatever the
// tag, and withholds `latest*.yml` from a prerelease. So a reader on preview ran
// the documented `gh release download --pattern 'latest-mac.yml'`, fetched
// nothing, was told nothing, and had no way to learn from the page that the file
// they wanted was `beta-mac.yml`.
//
// The recipe is reproducible by a user rather than by a maintainer, so a name it
// omits is a name nobody can be expected to guess.
// @link ../../.docs/runbooks/verify-release.md

const VERIFY_RECIPE_WORKFLOW = '.github/workflows/release.yml';

const VERIFY_RECIPE_PAGE = '.docs/runbooks/verify-release.md';

/** the one root both composer roots agree on — mobile-app/Modules is a symlink onto this tree */
function verifyRecipeRead(string $relative): string
{
    foreach ([$relative, '../'.$relative] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

/**
 * The update manifests the pipeline writes, by the name they carry onto the
 * release page. Read off the workflow rather than listed here, so a fourth
 * platform's manifest is in scope the day it is emitted.
 *
 * @return list<string>
 */
function verifyRecipeManifestNames(string $workflow): array
{
    /** @var list<string> $found */
    $found = PatternScan::all('/\b(?:latest|beta)(?:-[a-z]+)?\.yml\b/', $workflow)[0];

    $names = array_values(array_unique($found));
    sort($names);

    return $names;
}

it('finds a pipeline and a recipe to compare it against', function (): void {
    $workflow = verifyRecipeRead(VERIFY_RECIPE_WORKFLOW);
    $page = verifyRecipeRead(VERIFY_RECIPE_PAGE);

    expect($workflow)->not->toBe('', VERIFY_RECIPE_WORKFLOW.' was not found from either composer root')
        ->and($page)->not->toBe('', VERIFY_RECIPE_PAGE.' was not found from either composer root');

    $names = verifyRecipeManifestNames($workflow);

    // Both channels, not a count: a pattern that lost one family would still
    // clear a floor on the total while the rule below stopped reading half the
    // release page.
    $channels = [];
    foreach ($names as $name) {
        $channels[str_starts_with($name, 'beta') ? 'preview' : 'stable'] = true;
    }

    expect(array_keys($channels))->toHaveCount(2, implode("\n", [
        'The manifest scan found these names in '.VERIFY_RECIPE_WORKFLOW.':',
        ...($names === [] ? ['(none)'] : $names),
        '',
        'Both a stable `latest*.yml` and a preview `beta*.yml` are expected. One',
        'family missing means either the pipeline stopped publishing a channel or',
        'the pattern stopped matching it, and the rule below is then checking the',
        'recipe against half of what a release page carries.',
    ]));
});

it('names in the verify recipe every manifest the pipeline publishes', function (): void {
    $workflow = verifyRecipeRead(VERIFY_RECIPE_WORKFLOW);
    $page = verifyRecipeRead(VERIFY_RECIPE_PAGE);

    $unnamed = [];

    foreach (verifyRecipeManifestNames($workflow) as $name) {
        if (! str_contains($page, $name)) {
            $unnamed[] = $name;
        }
    }

    expect($unnamed)->toBe([], implode("\n", [
        'The pipeline publishes these manifests and the verify recipe never names them:',
        ...$unnamed,
        '',
        'The recipe has to be reproducible by a user, and it tells one to download',
        'a manifest by name. A name it omits is a name nobody can guess: the',
        'download comes back empty and says nothing about why. Name each in',
        VERIFY_RECIPE_PAGE.', beside the set it already documents.',
    ]));
});
