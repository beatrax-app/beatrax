<?php

declare(strict_types=1);

use Tests\Contracts\Support\RepoTree;

// Without viewport-fit=cover iOS reports every safe-area inset as 0, so a
// layout that omits it reserves nothing and draws over the status bar however
// carefully the CSS asks for --safe-top. Read on an iPhone 12 mini: the Dev
// Console heading and the clock printed on top of each other.

/**
 * Every template that declares a viewport at all, which is the set this rule is
 * about: a partial with no <meta name="viewport"> is not a document and reserves
 * nothing either way. Read off the declaration rather than off a layouts/ glob,
 * because the error page carries its own <head> and sits under components/.
 *
 * @return array<string, string> repo-relative path => source
 */
function notchViewportBlades(): array
{
    $documents = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $source = (string) file_get_contents($path);

        if (str_contains($source, '<meta name="viewport"')) {
            $documents[str_replace(RepoTree::root().'/', '', $path)] = $source;
        }
    }

    ksort($documents);

    return $documents;
}

it('declares viewport-fit=cover in every layout', function (): void {
    $documents = notchViewportBlades();

    // Five templates declare a viewport today: the two root layouts, the Dev
    // Console and onboarding shells, and the standalone error page. A run that
    // found none of them measured nothing.
    expect(count($documents))->toBeGreaterThan(
        2,
        'No template declares a viewport at all, so this rule read nothing.'
    );

    $missing = array_keys(array_filter(
        $documents,
        static fn (string $source): bool => ! str_contains($source, 'viewport-fit=cover'),
    ));

    expect($missing)->toBe([], implode("\n  ", [
        'These declare a viewport without viewport-fit=cover:',
        ...$missing,
        '',
        'iOS then reports every safe-area inset as 0, so the page reserves nothing',
        'and draws under the status bar and the home indicator however carefully',
        'the CSS asks for --safe-top.',
    ]));
});

it('gives the Dev Console rail a phone layout instead of 87pt of page', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, '.flex:has(> .dev-side)');
    expect($start)->not->toBeFalse('The stylesheet no longer carries a rule for the Dev Console rail at all.');

    $before = substr($css, 0, (int) $start);
    $lastMedia = strrpos($before, '@media (');
    expect($lastMedia)->not->toBeFalse('The Dev Console rail rule sits under no media query at all, so it applies on every width.');

    expect(str_contains(substr($before, (int) $lastMedia, 30), 'max-width: 767px'))->toBeTrue(
        'The Dev Console rail rule is no longer inside the phone-width media query, so it restacks the rail on a desktop too.'
    );

    // 800 characters is the rule as written with room to spare.
    $rule = substr($css, (int) $start, 800);

    $missing = array_values(array_filter(
        ['flex-direction: column;', 'padding-top: calc(var(--space-3) + var(--safe-top));'],
        static fn (string $part): bool => ! str_contains($rule, $part),
    ));

    expect($missing)->toBe([], implode("\n  ", [
        'The Dev Console rail rule no longer carries:',
        ...$missing,
        '',
        'Without the restack the rail takes 87pt of a 375pt screen, and without the',
        'safe-area top padding its heading prints on top of the clock.',
    ]));
});
