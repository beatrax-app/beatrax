<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// An `x-data` naming a factory nothing registers still binds: Alpine reports one
// expression error, hands the element an empty scope, and every method the
// component was written around is simply absent. The page returns 200 and the
// suite stays green.
//
// The phone's notification bridge shipped that way. The registration was in
// resources/js/app.js and not in public/build, which is the script a device
// loads — so the operating system's dialog could not be raised and the answer
// could not be caught.
/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#an-alpine-provider-missing-from-the-script-that-ships
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-provider-registered-from-an-event-that-has-already-fired
 */

/**
 * The names an `x-data` resolves through a registration: the whole expression
 * is a call, or an object literal spreading one in. Everything else is data
 * the template carries itself and names nobody.
 *
 * @return array{values: int, providers: array<string, list<string>>}
 */
function alpineProviderNamesInTemplates(): array
{
    $values = 0;
    $providers = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $source = (string) file_get_contents($path);

        foreach (MarkupSource::tags($source) as $element) {
            $expression = $element->attribute('x-data');

            if ($expression === null) {
                continue;
            }

            $values++;

            foreach (PatternScan::sets('/(?:\A|\.\.\.)\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', trim($expression)) as $match) {
                $providers[$match[1]][] = str_replace(RepoTree::root().'/', '', $path);
            }
        }
    }

    ksort($providers);

    return ['values' => $values, 'providers' => array_map(
        static fn (array $where): array => array_values(array_unique($where)),
        $providers,
    )];
}

/**
 * Every name the front-end sources hand to Alpine. Stores and magics belong
 * here beside the component factories: all three are one write into one
 * registry, and all three vanish together.
 *
 * @return array<string, string> name => the file registering it
 */
function alpineRegistrationsInFrontEndSource(): array
{
    $registered = [];
    $root = RepoTree::root().'/resources/js';

    if (! is_dir($root)) {
        return $registered;
    }

    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($tree as $file) {
        if (! $file instanceof SplFileInfo || ! str_ends_with($file->getPathname(), '.js')) {
            continue;
        }

        foreach (PatternScan::sets(ALPINE_REGISTRATION_PATTERN, (string) file_get_contents($file->getPathname())) as $match) {
            $registered[$match[1]] = str_replace(RepoTree::root().'/', '', $file->getPathname());
        }
    }

    ksort($registered);

    return $registered;
}

// The call, not the name: `Alpine.store('overlay')` with no second argument
// reads a store and registers nothing, and counting it as a registration is how
// a guard reports a registry it never checked.
const ALPINE_REGISTRATION_PATTERN = '/\.(?:data|magic|store)\(\s*[\'"`]([A-Za-z_$][A-Za-z0-9_$]*)[\'"`]\s*,/';

function alpineRegistersInScript(string $name, string $script): bool
{
    return PatternScan::matches(
        '/\.(?:data|magic|store)\(\s*[\'"`]'.preg_quote($name, '/').'[\'"`]\s*,/',
        $script,
    );
}

/**
 * Whether $script hands its providers to Alpine only from an `alpine:init`
 * listener. That event is dispatched once, by `Alpine.start()`. `wire:navigate`
 * re-executes a page's body scripts on arrival and never restarts Alpine, so a
 * listener added then is waiting for something that has already happened —
 * every registration behind it is simply never made, and the once-guard these
 * blocks carry means there is no second attempt.
 *
 * Measured by position rather than by scope: a registration written before the
 * listener is one the eager path reaches, which is the shape
 * `resources/js/app.js` uses and the shape this asks for.
 */
function alpineRegistersOnlyOnInit(string $script): bool
{
    $listener = strpos($script, 'alpine:init');

    if ($listener === false) {
        return false;
    }

    $registrations = PatternScan::allWithOffsets(ALPINE_REGISTRATION_PATTERN, $script)[0];

    foreach ($registrations as $registration) {
        if ($registration[1] < $listener) {
            return false;
        }
    }

    return $registrations !== [];
}

/**
 * @return list<array{template: string, name: string, script: string}> every provider a template registers in a <script> of its own
 */
function alpineProvidersRegisteredByTemplates(): array
{
    $registered = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $source = (string) file_get_contents($path);

        foreach (MarkupSource::elements($source, 'script') as $script) {
            $body = $script->innerOrFail();

            foreach (PatternScan::all(ALPINE_REGISTRATION_PATTERN, $body)[1] as $name) {
                $registered[] = [
                    'template' => str_replace(RepoTree::root().'/', '', $path),
                    'name' => $name,
                    'script' => $body,
                ];
            }
        }
    }

    return $registered;
}

/**
 * The built entry scripts, which is what a browser downloads. Read as they are
 * rather than rebuilt: a registration added to resources/js since the last
 * `npm run build` is exactly the state this rule exists to name.
 */
function alpineShippedScript(): string
{
    $built = glob(RepoTree::root().'/public/build/assets/app-*.js') ?: [];

    expect($built)->not->toBe([], 'No built script under public/build/assets. Run `npm run build` — without it this invariant cannot be checked and must not be skipped.');

    return implode("\n", array_map(static fn (string $path): string => (string) file_get_contents($path), $built));
}

it('has every provider a template names registered by the script that ships', function (): void {
    $named = alpineProviderNamesInTemplates();
    $shipped = alpineShippedScript();

    expect($named['values'])->toBeGreaterThan(50, 'Only '.$named['values'].' x-data expressions were read, so the verdict below is about markup nobody parsed.');
    expect(count($named['providers']))->toBeGreaterThan(5, 'Almost no provider was found in any template, so this rule proved nothing.');
    expect(strlen($shipped))->toBeGreaterThan(100000, 'The built script read is far too small to be this application, so every provider below would report as missing.');

    $orphans = [];

    foreach ($named['providers'] as $name => $templates) {
        foreach ($templates as $template) {
            $ownScript = (string) file_get_contents(RepoTree::root().'/'.$template);

            if (alpineRegistersInScript($name, $shipped) || alpineRegistersInScript($name, $ownScript)) {
                continue;
            }

            $orphans[] = $template.' → '.$name.'()';
        }
    }

    sort($orphans);

    expect($orphans)->toBe(
        [],
        "An x-data naming a factory nothing registers binds to an empty scope: the element renders, the\n".
        "page returns 200, and none of the component's methods exist. Register it in resources/js, or in\n".
        "the template's own <script>, and rebuild — the script under public/build is read as it is, so a\n".
        "registration written since the last `npm run build` is missing here for the same reason it is\n".
        "missing on a device.\n  ".implode("\n  ", $orphans),
    );
});

// The wider half of the same claim, and the one that catches a bundle nobody
// rebuilt: a name resources/js registers is a name some template is entitled to
// use, whether or not one does today.
it('has every registration in the front-end sources present in the script that ships', function (): void {
    $registered = alpineRegistrationsInFrontEndSource();
    $shipped = alpineShippedScript();

    expect(count($registered))->toBeGreaterThan(10, 'Only '.count($registered).' registrations were read out of resources/js, so this rule compared almost nothing.');
    expect(strlen($shipped))->toBeGreaterThan(100000, 'The built script read is far too small to be this application, so every registration below would report as missing.');

    $missing = [];

    foreach ($registered as $name => $source) {
        if (alpineRegistersInScript($name, $shipped)) {
            continue;
        }

        $missing[] = $source.' → '.$name;
    }

    expect($missing)->toBe(
        [],
        "These are registered in the front-end sources and absent from the built script under\n".
        "public/build, which is the file a browser and both device shells load. Neither `native:run` nor\n".
        "the desktop prebuild hooks run Vite, so whatever is on disk is what ships. Run `npm run build`.\n  ".implode("\n  ", $missing),
    );
});

// The rule above accepts a registration in the template's own <script>, and it
// asks only whether the name is there. This is the other half of that
// acceptance: WHEN the script hands it over. A provider registered from an
// event that has already fired is as absent as one nobody wrote.
it('has every provider a template registers of its own reachable without a second alpine:init', function (): void {
    $registered = alpineProvidersRegisteredByTemplates();

    expect($registered)->not->toBe(
        [],
        'No template registers an Alpine provider in a <script> of its own, so the rule above accepts a path '
        .'nothing takes and this one holds nothing to the clock. Either the reader stopped, or the acceptance '
        .'in the message above excuses a shape the tree no longer has.'
    );

    $late = [];

    foreach ($registered as $site) {
        if (alpineRegistersOnlyOnInit($site['script'])) {
            $late[] = $site['template'].' → '.$site['name'].'()';
        }
    }

    sort($late);

    expect($late)->toBe(
        [],
        "These are registered from an `alpine:init` listener and nowhere else. Alpine dispatches that event\n".
        "once, from Alpine.start(); wire:navigate re-runs a page's body scripts on arrival and never restarts\n".
        "Alpine, so the first reader to reach the page through a navigate link registers a listener for\n".
        "something that has already happened. The x-data then binds an empty scope — one expression error, a\n".
        "200, and every method absent. Register eagerly off `window.Alpine` and keep the listener as the\n".
        "fallback for the page that loads before Alpine exists, the way resources/js/app.js does.\n  ".implode("\n  ", $late),
    );
});

it('reads a registration made too late to happen, and leaves an eager one alone', function (): void {
    expect(alpineRegistersOnlyOnInit("document.addEventListener('alpine:init', () => { Alpine.data('x', () => ({})); });"))
        ->toBeTrue('the listener is the only path, so nothing registers on a wire:navigate arrival');

    expect(alpineRegistersOnlyOnInit("const r = (a) => a.data('x', f); if (window.Alpine) { r(window.Alpine); } else { document.addEventListener('alpine:init', () => r(window.Alpine), { once: true }); }"))
        ->toBeFalse('the eager branch registers before the listener is ever mentioned');

    expect(alpineRegistersOnlyOnInit("Alpine.data('x', () => ({}));"))
        ->toBeFalse('a registration at the top level of the script waits for nothing');

    expect(alpineRegistersOnlyOnInit("document.addEventListener('alpine:init', () => window.beatraxBoot());"))
        ->toBeFalse('a listener that registers no provider is not this rule\'s business');
});

it('reads the shapes an x-data uses to name a provider, and leaves a literal alone', function (): void {
    $named = static fn (string $expression): array => array_column(
        PatternScan::sets('/(?:\A|\.\.\.)\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\(/', trim($expression)),
        1,
    );

    expect($named('beatraxDatePicker({ value: 1 })'))->toBe(['beatraxDatePicker'], 'a bare call is the ordinary way a template names a factory');
    expect($named("{ ...copyToClipboard('x'), open: false }"))->toBe(['copyToClipboard'], 'a spread names one too, and it is the form that hides');
    expect($named('{ open: false }'))->toBe([], 'an object literal names nobody');
    expect($named('{ mode: @js($row->mode) }'))->toBe([], 'a Blade echo inside a literal is not a provider call');
    expect($named('{}'))->toBe([], 'the empty literal names nobody either');
});

it('reads a registration by its call and not by its name', function (): void {
    expect(alpineRegistersInScript('overlay', "Alpine.store('overlay', { names: [] });"))->toBeTrue();
    expect(alpineRegistersInScript('overlay', "window.Alpine?.store('overlay')?.add('drawer');"))->toBeFalse();
    expect(alpineRegistersInScript('palette', 'window.Alpine.data(`palette`,rl)'))->toBeTrue();
    expect(alpineRegistersInScript('plural', "Alpine.magic('plural', () => 1);"))->toBeTrue();
    expect(alpineRegistersInScript('tab', "Alpine.data('tabStrip', tabStrip);"))->toBeFalse();
});
