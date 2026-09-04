<?php

declare(strict_types=1);

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\Blade;
use Modules\Core\Public\Support\PatternScan;
use Tests\Helpers\CssRule;

/**
 * @link ../../.docs/conventions/an-icon-only-action-says-its-verb-on-touch.md
 */

// Measured in Chrome at a coarse pointer: the tip runs about 6.9px per
// character at --text-xs, so sixteen is roughly 120px — one word in every
// locale the app ships, and half the 359px a 375px screen leaves it.
// Seventeen means a phrase crept back into a box meant to hold a verb.
const TOUCH_CAPTION_MAX_CHARS = 16;

/** @return list<string> absolute paths to every Blade template that can hold a call site */
function touchCaptionBladeFiles(): array
{
    $files = [];

    foreach (['Modules', 'resources'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * The whole element, closing tag included, because an attribute here holds
 * `$pot->id` and a scan that stopped at the first ">" would cut the tag in half.
 *
 * @return list<array{file: string, line: int, label: string, caption: string}> the two lang keys each call site names
 */
function touchCaptionCallSites(): array
{
    $sites = [];

    foreach (touchCaptionBladeFiles() as $path) {
        $source = (string) file_get_contents($path);

        $matches = PatternScan::setsWithOffsets(
            '~<x-core::emoji-action\b(.*?)</x-core::emoji-action>~s',
            $source,
        );

        foreach ($matches as $match) {
            $body = $match[1][0];
            $label = PatternScan::first('~:label="Lang::get\(\'([^\']+)\'\)"~', $body);
            $caption = PatternScan::first('~:caption="Lang::get\(\'([^\']+)\'\)"~', $body);

            $sites[] = [
                'file' => $path,
                'line' => substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1,
                'label' => $label[1] ?? '',
                'caption' => $caption[1] ?? ($label[1] ?? ''),
            ];
        }
    }

    return $sites;
}

/** @return list<string> every locale the app ships */
function touchCaptionLocales(): array
{
    $locales = [];

    foreach ((array) glob(base_path('Modules/Core/Resources/lang/*'), GLOB_ONLYDIR) as $dir) {
        $locales[] = basename((string) $dir);
    }

    sort($locales);

    return $locales;
}

it('finds a resolvable label on every icon-only action', function (): void {
    $sites = touchCaptionCallSites();

    expect($sites)->not->toBe([]);

    $unresolvable = [];
    foreach ($sites as $site) {
        if ($site['label'] === '') {
            $unresolvable[] = $site['file'].':'.$site['line'];
        }
    }

    expect($unresolvable)->toBe([], "Every emoji-action names its label as :label=\"Lang::get('…')\", so the guard below can read it in all 26 languages. These do not:\n  ".implode("\n  ", $unresolvable));
});

it('hides the verb until it is held for, and leaves the accessible name alone', function (): void {
    $html = Blade::render('<x-core::emoji-action label="Archive" tone="danger">🗄️</x-core::emoji-action>');

    expect($html)->toContain('aria-label="Archive"')
        ->and($html)->toContain('title="Archive"')
        ->and($html)->toContain('x-data="emojiActionHold()"')
        ->and($html)->toContain('x-teleport="body"')
        ->and($html)->toContain('class="emoji-action__tip"')
        ->and($html)->toContain('>Archive</span>')
        // The word is no longer standing under the mark: that is the whole
        // change the reader asked for after seeing it on the phone.
        ->and($html)->not->toContain('emoji-action__caption');

    $shortened = Blade::render('<x-core::emoji-action label="Mark as complete" caption="Complete">✅</x-core::emoji-action>');

    expect($shortened)->toContain('aria-label="Mark as complete"')
        ->and($shortened)->toContain('title="Mark as complete"')
        ->and($shortened)->toContain('>Complete</span>');
});

// The one placement that makes the suppression work. At the target itself a
// capture listener and Livewire's own click listener run in registration
// order, and Livewire registers first — so a hold would archive the row it was
// only naming. On an ancestor, capture always runs first.
it('swallows the hold click on the wrapper, above the button that acts', function (): void {
    $html = Blade::render('<x-core::emoji-action label="Archive" wire:click="archive(1)">🗄️</x-core::emoji-action>');

    $wrapper = substr($html, 0, (int) strpos($html, '<button'));

    expect($wrapper)->toContain('class="emoji-action-hold"')
        ->and($wrapper)->toContain('x-on:click.capture="guard($event)"')
        ->and($wrapper)->toContain('x-on:pointerdown="press($event)"')
        ->and($wrapper)->toContain('x-on:pointermove="drift($event)"')
        ->and($wrapper)->toContain('x-on:pointercancel="reset()"');

    // Measured order on a touch screen: pointerdown, pointerup, pointerout,
    // pointerleave, click. A reset wired to pointerleave lands in the gap
    // between arming the guard and the click it was armed for, and the hold
    // archived the row. pointercancel is the only cancel that precedes no click.
    expect($wrapper)->not->toContain('pointerleave');

    expect($html)->toContain('wire:click="archive(1)"');
});

// WCAG 2.5.3 in the one place this repo's static label-in-name guard cannot
// look: both strings arrive through Lang::get, so it skips them as interpolated
// and every locale goes unchecked. A caption a voice-control user reads aloud
// has to be inside the name the button answers to.
it('keeps every caption inside its own accessible name, in all 26 languages', function (): void {
    $translator = app(Translator::class);
    $offenders = [];

    foreach (touchCaptionCallSites() as $site) {
        foreach (touchCaptionLocales() as $locale) {
            $label = $translator->get($site['label'], [], $locale, false);
            $caption = $translator->get($site['caption'], [], $locale, false);

            if (! is_string($label) || ! is_string($caption) || $label === $site['label'] || $caption === $site['caption']) {
                $offenders[] = sprintf('%s:%d [%s] — no line behind %s', $site['file'], $site['line'], $locale, $label === $site['label'] ? $site['label'] : $site['caption']);

                continue;
            }

            if (! str_contains(mb_strtolower($label), mb_strtolower($caption))) {
                $offenders[] = sprintf('%s:%d [%s] — shows "%s", announces "%s"', $site['file'], $site['line'], $locale, $caption, $label);
            }
        }
    }

    expect($offenders)->toBe([], "A caption is a word taken out of the label, never a second wording of it. Offenders:\n  ".implode("\n  ", $offenders));
});

it('keeps every caption down to the one word a 44px mark can carry', function (): void {
    $translator = app(Translator::class);
    $offenders = [];

    foreach (touchCaptionCallSites() as $site) {
        foreach (touchCaptionLocales() as $locale) {
            $caption = $translator->get($site['caption'], [], $locale, false);

            if (! is_string($caption)) {
                continue;
            }

            if (mb_strlen($caption) > TOUCH_CAPTION_MAX_CHARS) {
                $offenders[] = sprintf('%s:%d [%s] — %d chars: "%s"', $site['file'], $site['line'], $locale, mb_strlen($caption), $caption);
            }
        }
    }

    expect($offenders)->toBe([], sprintf(
        "A label longer than %d characters needs a :caption naming the verb alone — measured, the Spanish tax label drew a 218px button under a 44px mark. Offenders:\n  %s",
        TOUCH_CAPTION_MAX_CHARS,
        implode("\n  ", $offenders)
    ));
});

it('places the tip against the viewport rather than inside what clips it', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // .pots-phone-list is overflow-hidden and the calendar day panel is
    // transformed AND scroll-clipped. Anything but fixed is cut off in both.
    // Leaving those clips also leaves the modal stacking context, so at the
    // app scale's top of 80 the tip painted nothing inside the z-[9999]
    // command palette. Its rank is pinned by the sibling Core test.
    expect(CssRule::blockFor($css, '.emoji-action__tip'))
        ->toContain('position: fixed')
        ->toContain('z-index: 10001')
        ->toContain('pointer-events: none');

    expect(CssRule::blockFor($css, '.emoji-action-hold'))->toContain('display: contents');

    // The coarse block is unlayered on purpose: inside @layer components it
    // loses to the Tailwind utility sitting on the same button.
    $coarse = touchCaptionCoarseBlocks($css);

    expect($coarse)->not->toBe('')
        ->and($coarse)->toMatch('~\.emoji-action\s*\{[^}]*-webkit-touch-callout:\s*none~')
        ->and($coarse)->toMatch('~\.emoji-action\s*\{[^}]*user-select:\s*none~');
});

// The pots row is the only one that carries five of these. In German they
// measure 333px against 309px of row at 375px, and the list around them is
// overflow-hidden, so an unwrapped row loses the last action outright.
it('lets a row of five actions wrap rather than clip its last one', function (): void {
    $blade = (string) file_get_contents(base_path('Modules/Pots/Resources/views/livewire/pots-page.blade.php'));

    $row = PatternScan::first('~<div class="(flex w-full[^"]*)">\s*\{\{--~', $blade);

    expect($row[1] ?? '')->toContain('flex-wrap');
});

/** @return string every unlayered `(pointer: coarse)` block in the stylesheet, concatenated */
function touchCaptionCoarseBlocks(string $css): string
{
    $blocks = '';
    $offset = 0;

    while (($start = strpos($css, '@media (pointer: coarse)', $offset)) !== false) {
        $depth = 0;
        $open = strpos($css, '{', $start);

        if ($open === false) {
            break;
        }

        for ($i = $open; $i < strlen($css); $i++) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);
            if ($depth === 0) {
                $blocks .= substr($css, $open, $i - $open);
                $offset = $i;

                break;
            }
        }

        if ($depth !== 0) {
            break;
        }
    }

    return $blocks;
}
