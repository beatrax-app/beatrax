<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// Two alert sentences ended "Run `php artisan beatrax:doctor` for guidance",
// system-wide, in the banner every reader sees. A shipped desktop bundle has no
// terminal and a store build has no artisan, so the one instruction the sentence
// gave was the one thing its audience could not do — and it said it in
// twenty-six languages. The diagnostic was worth surfacing; the remedy was
// written for the person who built the app.
const TERMINAL_INSTRUCTIONS = [
    'php artisan',
    './vendor/bin/',
    'composer ',
    'npm run',
    'sudo ',
];

/**
 * Every reader-facing translation file the repository walks.
 *
 * @return list<string> absolute paths, as the scope yields them
 */
function readerFacingLangFiles(): array
{
    $files = [];

    foreach (RepoTree::files(RepoTree::EVERY_PHP_FILE) as $path) {
        // `Resources/lang` is what a screen resolves through. A fixture or a
        // scanner naming a command is not copy and is not the subject.
        if (str_contains($path, '/Resources/lang/') && str_ends_with($path, '.php')) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

it('never hands a reader an instruction only a terminal can carry out', function (): void {
    $files = readerFacingLangFiles();

    // The denominator: 26 locales across the module tree. An empty walk would
    // report a product that tells nobody to open a terminal, having read no
    // sentence at all.
    expect(count($files))->toBeGreaterThan(200, 'read '.count($files).' translation files, too few for an empty offender list to mean anything');

    $offenders = [];

    // The scope yields absolute paths; only the message is relativised, so a
    // reader is shown the path they would open.
    $root = base_path().'/';

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        foreach (TERMINAL_INSTRUCTIONS as $instruction) {
            if (str_contains($source, $instruction)) {
                $offenders[] = str_replace($root, '', $path).' names `'.trim($instruction).'`';
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", [
        'A translated sentence tells the reader to run something at a command line. The desktop bundle',
        'ships no terminal and a store build ships no artisan, so this is an instruction its whole',
        'audience is unable to follow — and it is repeated in every locale that carries the key.',
        'Say what the condition means and what the reader can actually do about it; if the remedy is',
        'genuinely a developer action, the sentence belongs on a developer surface, not in the banner.',
        'Offenders:',
        ...$offenders,
    ]));
});

// The reader that finds nothing has to be able to find something, or the rule
// above passes on a walk that never opened a file.
it('reads a planted instruction and leaves an ordinary sentence alone', function (): void {
    $planted = "<?php return ['x' => 'Run php artisan beatrax:doctor for guidance.'];";
    $ordinary = "<?php return ['x' => 'Restarting Beatrax usually clears this.'];";

    $hits = static fn (string $source): bool => array_reduce(
        TERMINAL_INSTRUCTIONS,
        static fn (bool $carry, string $needle): bool => $carry || str_contains($source, $needle),
        false,
    );

    expect($hits($planted))->toBeTrue('the reader no longer recognises the sentence this rule was written for')
        ->and($hits($ordinary))->toBeFalse('the reader reports a sentence that names no command, so every locale is an offender');

    expect(PatternScan::matches('/artisan/', $ordinary))->toBeFalse();
});
