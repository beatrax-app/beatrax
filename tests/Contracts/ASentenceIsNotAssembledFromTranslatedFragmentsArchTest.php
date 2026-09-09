<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// A sentence split into prefix + variable + suffix keys can only be reassembled
// in the order the splitter's own language puts them. The doctor panel's empty
// state was three keys with the command name appended last, so every locale had
// to end the sentence with the command whether or not its grammar allows it:
//
//   nl  "om aan te roepen beatrax:doctor"   (the verb belongs after the object)
//   et  "et see käivitada beatrax:doctor"   (names the command twice)
//   fi  "käynnistääksesi sen beatrax:doctor"          likewise
//   lv  "lai to izsauktu beatrax:doctor"               likewise
//
// One key with a placeholder can be reordered per language, which is the only
// shape that lets a translator put the object where their language wants it.

/** @return array<string, array<string, string>> locale => flattened doctor strings */
function doctorStringsPerLocale(): array
{
    $root = base_path('Modules/DevMode/Resources/lang');
    $out = [];

    foreach ((new Finder)->directories()->in($root)->depth(0) as $dir) {
        $file = $dir->getPathname().'/doctor.php';

        if (! is_file($file)) {
            continue;
        }

        /** @var array<string, string> $strings */
        $strings = require $file;
        $out[$dir->getFilename()] = $strings;
    }

    return $out;
}

it('carries the doctor empty state as one sentence in every locale it ships', function (): void {
    $locales = doctorStringsPerLocale();

    expect(count($locales))->toBeGreaterThan(20, 'the walk found almost no locales, so a clean answer below means nothing');

    $broken = [];

    foreach ($locales as $locale => $strings) {
        foreach (['empty_prefix', 'empty_rerun', 'empty_suffix'] as $fragment) {
            if (array_key_exists($fragment, $strings)) {
                $broken[] = $locale.' still carries '.$fragment;
            }
        }

        $sentence = $strings['empty_html'] ?? null;

        if (! is_string($sentence)) {
            $broken[] = $locale.' has no empty_html';

            continue;
        }

        foreach ([':action', ':command'] as $placeholder) {
            if (! str_contains($sentence, $placeholder)) {
                $broken[] = $locale.' drops '.$placeholder;
            }
        }
    }

    expect($broken)->toBe([], implode("\n  ", array_merge(
        ['The empty state is one sentence with two placeholders, per locale:'],
        $broken,
    )));
});

// The control: if every locale still ended on the command, the shape above
// would be the old defect with new key names. At least one language has to be
// putting something after it, or nothing was actually freed.
it('lets a locale put words after the command, which the appended form could not', function (): void {
    $after = [];

    foreach (doctorStringsPerLocale() as $locale => $strings) {
        $sentence = $strings['empty_html'] ?? '';
        $tail = trim(substr($sentence, (int) strpos($sentence, ':command') + strlen(':command')), " \t.");

        if ($tail !== '') {
            $after[$locale] = $tail;
        }
    }

    expect($after)->not->toBe([], 'every locale still ends on the command, so the placeholder bought nothing');
    expect($after)->toHaveKey('nl');
    expect($after['nl'])->toBe('aan te roepen');
});

// The word the sentence tells the reader to press and the word on the button
// are one key, so a translator cannot change one and leave the other.
it('names the button by the button\'s own key', function (): void {
    $blade = (string) file_get_contents(
        base_path('Modules/DevMode/Resources/views/livewire/doctor-panel-page.blade.php'),
    );

    expect($blade)->toContain("'action' => '<span class=\"font-semibold\">'.e(Lang::get('dev::doctor.rerun')).'</span>'")
        ->and($blade)->not->toContain('empty_prefix');
});
