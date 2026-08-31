<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Illuminate\Translation\MessageSelector;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Http\Livewire\CommandPaletteModal;
use Modules\DevMode\Internal\Http\Livewire\LogTailerPage;
use Symfony\Component\Process\Process;

// The palette rendered "See all 1 results" because the count was concatenated
// in JavaScript, where trans_choice cannot reach. The arms now travel to the
// browser with the reader locale's own index table, so these run the shipped
// chooser rather than asserting the payload looks right.

function browserPluralRepoRoot(): string
{
    return dirname((string) realpath(__DIR__), 4);
}

function browserPluralIsRunnable(): bool
{
    return is_file(browserPluralRepoRoot().'/Modules/DevMode/tests/Fixtures/plural-probe.mjs')
        && is_file(browserPluralRepoRoot().'/resources/js/lang.js');
}

/**
 * @param  array{span: int, index: list<int>, forms: array<string, list<string>>}  $arms
 * @param  list<array{label: string, key: string, number: int, replace?: array<string, string>}>  $asks
 * @return array<string, string>
 */
function browserPluralAnswers(array $arms, array $asks): array
{
    $root = browserPluralRepoRoot();

    $process = new Process(['node', $root.'/Modules/DevMode/tests/Fixtures/plural-probe.mjs'], $root);
    $process->setInput((string) json_encode(['arms' => $arms, 'asks' => $asks]));
    $process->run();

    expect($process->isSuccessful())->toBeTrue('the plural probe failed: '.$process->getErrorOutput());

    $decoded = json_decode($process->getOutput(), true);
    expect($decoded)->toBeArray();

    /** @var array<string, string> $decoded */
    return $decoded;
}

/** @return list<array{label: string, key: string, number: int}> */
function browserPluralAsks(string $key, int ...$numbers): array
{
    return array_map(
        static fn (int $number): array => ['label' => (string) $number, 'key' => $key, 'number' => $number],
        $numbers,
    );
}

it('says one result in the singular and two in the plural, in English', function (): void {
    App::setLocale(Locale::En->value);

    $answers = browserPluralAnswers(
        Lang::arms('dev::palette.see_all', 'dev::palette.results'),
        [
            ['label' => 'see_all_1', 'key' => 'dev::palette.see_all', 'number' => 1],
            ['label' => 'see_all_2', 'key' => 'dev::palette.see_all', 'number' => 2],
            ['label' => 'results_1', 'key' => 'dev::palette.results', 'number' => 1],
            ['label' => 'results_2', 'key' => 'dev::palette.results', 'number' => 2],
        ],
    );

    expect($answers['see_all_1'])->toBe('See 1 result →')
        ->and($answers['see_all_2'])->toBe('See all 2 results →')
        ->and($answers['results_1'])->toBe('1 result')
        ->and($answers['results_2'])->toBe('2 results');
})->skip(! browserPluralIsRunnable(), 'the plural probe or the module it runs is missing.');

// Slovenian selects four arms and Polish three, so a browser deciding between
// two forms cannot be right for either: what a JS `n === 1 ? a : b` would give
// at 3 and at 5 is the same string, and neither language accepts that.
it('picks a fourth Slovenian arm and a third Polish one the same way PHP would', function (): void {
    $wrong = [];

    foreach (['sl' => 4, 'pl' => 3] as $locale => $expectedArms) {
        App::setLocale($locale);

        $arms = Lang::arms('dev::palette.results');
        expect($arms['forms']['dev::palette.results'])->toHaveCount($expectedArms);

        $numbers = [0, 1, 2, 3, 5, 21, 101, 102];
        $answers = browserPluralAnswers($arms, browserPluralAsks('dev::palette.results', ...$numbers));

        foreach ($numbers as $number) {
            $server = Lang::choice('dev::palette.results', $number);
            if ($answers[(string) $number] !== $server) {
                $wrong[] = $locale.' at '.$number.': browser ['.$answers[(string) $number].'], server ['.$server.']';
            }
        }

        $distinct = array_unique(array_values($answers));
        if (count($distinct) < $expectedArms) {
            $wrong[] = $locale.' reached only '.count($distinct).' of its '.$expectedArms.' arms across '.implode(', ', $numbers);
        }
    }

    expect($wrong)->toBe([], "The browser picked an arm the reader's language would not:\n  ".implode("\n  ", $wrong));
})->skip(! browserPluralIsRunnable(), 'the plural probe or the module it runs is missing.');

it('agrees with trans_choice in every locale the app ships', function (): void {
    $numbers = [0, 1, 2, 3, 4, 5, 11, 21, 22, 101, 111, 122];
    $wrong = [];

    foreach (Locale::cases() as $case) {
        App::setLocale($case->value);

        $answers = browserPluralAnswers(
            Lang::arms('dev::palette.results'),
            browserPluralAsks('dev::palette.results', ...$numbers),
        );

        foreach ($numbers as $number) {
            $server = Lang::choice('dev::palette.results', $number);
            if ($answers[(string) $number] !== $server) {
                $wrong[] = $case->value.' at '.$number.': browser ['.$answers[(string) $number].'], server ['.$server.']';
            }
        }
    }

    expect($wrong)->toBe([], "The shipped table disagrees with the selector it was built from:\n  ".implode("\n  ", $wrong));
})->skip(! browserPluralIsRunnable(), 'the plural probe or the module it runs is missing.');

// The table holds one entry per number below the span and one per residue above
// it, which is only sound because no rule in MessageSelector compares the number
// itself to anything that large. Asserted rather than read off the source: a
// rule added later that does would make every large count pick the wrong arm.
it('holds a table whose second block answers for every number above it', function (): void {
    $selector = new MessageSelector;
    $span = intdiv(count(Lang::arms('dev::palette.results')['index']), 2);
    $drift = [];

    foreach (Locale::cases() as $case) {
        foreach (range(0, $span - 1) as $residue) {
            $reference = $selector->getPluralIndex($case->value, $span + $residue);

            foreach ([2, 3, 9] as $block) {
                $number = $block * $span + $residue;
                if ($selector->getPluralIndex($case->value, $number) !== $reference) {
                    $drift[] = $case->value.': '.$number.' does not select what '.($span + $residue).' does';
                }
            }
        }
    }

    expect($span)->toBe(100);
    expect($drift)->toBe([], "The plural table cannot be extrapolated past its span:\n  ".implode("\n  ", array_slice($drift, 0, 20)));
});

it('hands the palette both arms of every counted row it draws', function (): void {
    App::setLocale(Locale::Sl->value);

    $user = User::query()->create([
        'username' => 'palette-plural-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);

    $html = Livewire::actingAs($user)->test(CommandPaletteModal::class)->html();

    foreach (Lang::arms('dev::palette.see_all', 'dev::palette.results')['forms'] as $forms) {
        foreach ($forms as $form) {
            expect($html)->toContain($form);
        }
    }
});

// The empty-results line holds the reader's query between quotation marks, and
// a raw " inside a double-quoted attribute ends the tag: the rest of the
// expression becomes page text and Alpine reports an undefined property
// somewhere else entirely. Js::from hex-escapes it, and that is what ships.
it('escapes the quotation marks the empty-results line renders around a query', function (): void {
    App::setLocale(Locale::En->value);

    $user = User::query()->create([
        'username' => 'palette-query-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);

    $html = Livewire::actingAs($user)->test(CommandPaletteModal::class)->html();

    expect($html)->toContain('x-text="$line(\'No transactions match \\u0022:query\\u0022\', { query })"');
});

// Lang::arms is a translation call EveryKeyACallSiteNamesResolvesToALineArchTest
// does not know the name of, so a key misspelled there resolves to itself and
// renders as one. Every key any call site hands it is checked here instead.
it('names only keys that resolve, everywhere Lang::arms is called', function (): void {
    $roots = [browserPluralRepoRoot().'/Modules', browserPluralRepoRoot().'/resources/views'];
    $keys = [];
    $files = 0;

    foreach ($roots as $root) {
        /** @var iterable<SplFileInfo> $walk */
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || str_contains($path, '/tests/') || str_contains($path, '/Resources/lang/')) {
                continue;
            }
            if (! str_ends_with($path, '.php')) {
                continue;
            }

            $files++;
            if (preg_match_all("/Lang::arms\(([^)]*)\)/", (string) file_get_contents($path), $calls) === false) {
                throw new RuntimeException('the Lang::arms scan stopped reading: '.preg_last_error_msg());
            }

            foreach ($calls[1] as $arguments) {
                preg_match_all("/'([^']+)'/", $arguments, $found);
                foreach ($found[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }
    }

    expect($files)->toBeGreaterThan(0);
    expect($keys)->not->toBeEmpty();

    App::setLocale(Locale::En->value);
    $unresolved = [];

    foreach (array_keys($keys) as $key) {
        $forms = Lang::arms($key)['forms'][$key];
        if ($forms === [$key] || count($forms) < 2) {
            $unresolved[] = $key;
        }
    }

    expect($unresolved)->toBe([], implode("\n", [
        'These keys are handed to Lang::arms and are not pluralised English lines:',
        ...$unresolved,
        '',
        'arms() exists to carry a choice into the browser. A key with no arms behind',
        'it either does not resolve — and renders as itself — or wants $line() and a',
        'placeholder instead.',
    ]));
});

it('hands the log tailer whole lines for the figures it counts in the browser', function (): void {
    App::setLocale(Locale::Cs->value);

    $user = User::query()->create([
        'username' => 'log-tailer-plural-reader',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);

    $html = Livewire::actingAs($user)->test(LogTailerPage::class)->html();

    $arms = Lang::arms(
        'dev::logs.totals.showing',
        'dev::logs.totals.lines_today',
        'dev::logs.totals.lines_today_capped',
        'dev::logs.totals.all_files',
    );

    foreach ($arms['forms'] as $forms) {
        expect($html)->toContain(end($forms));
    }

    expect($arms['forms']['dev::logs.totals.lines_today'])->toHaveCount(3);
});
