<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
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
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-template-names-a-store-the-script-never-registered
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

// Alpine's own magics, plus the ones Livewire's bundle brings with it. A name
// here resolves without anybody in this repository registering it, so reading
// one as a missing provider would report the framework as broken.
const ALPINE_BUILT_IN_MAGICS = ['el', 'refs', 'store', 'watch', 'dispatch', 'nextTick', 'root', 'data', 'id', 'persist', 'focus', 'anchor', 'wire', 'parent', 'js'];

/**
 * Whether an attribute carries an Alpine expression. `x-` and the `@event`
 * shorthand always do. The `:` shorthand does on an HTML element and does not
 * on a Blade component tag, where `:prop="$fn($row)"` is PHP the compiler
 * evaluates — reading that as JavaScript is how a closure a template defined
 * for itself reports as an unregistered magic.
 */
function alpineExpressionAttribute(string $element, string $name): bool
{
    if (str_starts_with($name, 'x-') || str_starts_with($name, '@')) {
        return true;
    }

    return str_starts_with($name, ':') && ! str_starts_with($element, 'x-') && ! str_contains($element, ':');
}

// Not preceded by a word character or a dot, which is the whole of the
// difference between the `$set` Livewire hangs off `$wire` and a magic of that
// name: `$wire.$set('a', 1)` names no magic at all.
const ALPINE_STORE_READ_PATTERN = '/(?<![\w.$])\$store\s*\.\s*([A-Za-z_$][A-Za-z0-9_$]*)/';

const ALPINE_MAGIC_CALL_PATTERN = '/(?<![\w.$])\$([A-Za-z_][A-Za-z0-9_$]*)\s*\(/';

/**
 * The stores and magics one Alpine expression names. A Blade island inside the
 * value is subtracted rather than read: `x-text="'{{ $money($row) }}'"` hands
 * the browser the result, never the call, and the call is PHP.
 *
 * @return array{stores: list<string>, magics: list<string>}
 */
function alpineRegistryNamesIn(string $element, string $attribute, string $value): array
{
    if ($value === '' || ! alpineExpressionAttribute(strtolower($element), $attribute)) {
        return ['stores' => [], 'magics' => []];
    }

    $php = BladePhpSource::of($value);
    $magics = [];

    foreach (PatternScan::all(ALPINE_MAGIC_CALL_PATTERN, $value)[1] as $name) {
        $compiled = PatternScan::matches('/(?<![\w.$])\$'.preg_quote($name, '/').'\s*\(/', $php);

        if (! in_array($name, ALPINE_BUILT_IN_MAGICS, true) && ! $compiled) {
            $magics[] = $name;
        }
    }

    return [
        'stores' => array_values(array_unique(PatternScan::all(ALPINE_STORE_READ_PATTERN, $value)[1])),
        'magics' => array_values(array_unique($magics)),
    ];
}

/**
 * The same question the rule above asks of `x-data`, asked of the other two
 * registries. A factory is named in one attribute and nowhere else; a store and
 * a magic are reachable from every expression on the page — `$store.overlay` in
 * an `x-on:click`, `$plural(…)` in an `x-text` — and they go missing from a
 * stale bundle in exactly the same silence.
 *
 * @return array{attributes: int, stores: array<string, list<string>>, magics: array<string, list<string>>}
 */
function alpineRegistryNamesInTemplates(): array
{
    $attributes = 0;
    $stores = [];
    $magics = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $where = str_replace(RepoTree::root().'/', '', $path);

        foreach (MarkupSource::tags((string) file_get_contents($path)) as $element) {
            foreach ($element->attributes() as $name => $value) {
                if ($value === '' || ! alpineExpressionAttribute(strtolower($element->name), $name)) {
                    continue;
                }

                $attributes++;
                $named = alpineRegistryNamesIn($element->name, $name, $value);

                foreach ($named['stores'] as $store) {
                    $stores[$store][] = $where;
                }

                foreach ($named['magics'] as $magic) {
                    $magics[$magic][] = $where;
                }
            }
        }
    }

    ksort($stores);
    ksort($magics);

    $unique = static fn (array $where): array => array_values(array_unique($where));

    return ['attributes' => $attributes, 'stores' => array_map($unique, $stores), 'magics' => array_map($unique, $magics)];
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

    foreach (alpineFrontEndScripts() as $where => $script) {
        foreach (PatternScan::sets(ALPINE_REGISTRATION_PATTERN, $script) as $match) {
            $registered[$match[1]] = $where;
        }
    }

    ksort($registered);

    return $registered;
}

/**
 * The front-end modules as written, keyed by their path from the repository
 * root. Read once and shared: the rule above asks WHICH names they register
 * and the rule at the foot of this file asks WHEN, and a second walk that
 * drifted from this one would leave a file guarded by one and not the other.
 *
 * @return array<string, string> path => source
 */
function alpineFrontEndScripts(): array
{
    $root = RepoTree::root().'/resources/js';

    if (! is_dir($root)) {
        return [];
    }

    $scripts = [];
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($tree as $file) {
        if (! $file instanceof SplFileInfo || ! str_ends_with($file->getPathname(), '.js')) {
            continue;
        }

        $scripts[str_replace(RepoTree::root().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
    }

    ksort($scripts);

    return $scripts;
}

// The call, not the name: `Alpine.store('overlay')` with no second argument
// reads a store and registers nothing, and counting it as a registration is how
// a guard reports a registry it never checked.
const ALPINE_REGISTRATION_PATTERN = '/\.(?:data|magic|store)\(\s*[\'"`]([A-Za-z_$][A-Za-z0-9_$]*)[\'"`]\s*,/';

// Alpine keeps the three registries apart, so the kind matters as much as the
// name: a name written into the magic registry is not reachable as `$store.x`,
// and a template asking the wrong registry is handed undefined in silence.
function alpineRegistersInScript(string $name, string $script, string $kinds = 'data|magic|store'): bool
{
    return PatternScan::matches(
        '/\.(?:'.$kinds.')\(\s*[\'"`]'.preg_quote($name, '/').'[\'"`]\s*,/',
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
 * Every script in this repository that hands Alpine a provider, whatever kind
 * of file it is: a `<script>` a template carries, and a module under
 * resources/js. The timing question is the same in both and so is the silence
 * when the answer is wrong — a store registered too late is not registered.
 *
 * The `kind` is carried so the rule reading this can prove it saw both, rather
 * than passing because one of the two sets went empty.
 *
 * @return list<array{kind: string, where: string, name: string, script: string}>
 */
function alpineProviderRegistrationSites(): array
{
    $registered = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $source = (string) file_get_contents($path);

        foreach (MarkupSource::elements($source, 'script') as $script) {
            $body = $script->innerOrFail();

            foreach (PatternScan::all(ALPINE_REGISTRATION_PATTERN, $body)[1] as $name) {
                $registered[] = [
                    'kind' => 'template',
                    'where' => str_replace(RepoTree::root().'/', '', $path),
                    'name' => $name,
                    'script' => $body,
                ];
            }
        }
    }

    foreach (alpineFrontEndScripts() as $where => $script) {
        foreach (PatternScan::all(ALPINE_REGISTRATION_PATTERN, $script)[1] as $name) {
            $registered[] = [
                'kind' => 'module',
                'where' => $where,
                'name' => $name,
                'script' => $script,
            ];
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

it('has every store and magic a template names registered by the script that ships', function (): void {
    $named = alpineRegistryNamesInTemplates();
    $shipped = alpineShippedScript();

    expect($named['attributes'])->toBeGreaterThan(300, 'Only '.$named['attributes'].' Alpine expressions were read, so the verdict below is about markup nobody parsed.');
    expect(count($named['stores']) + count($named['magics']))->toBeGreaterThan(3, 'Almost no store or magic was found in any template, so this rule proved nothing.');
    expect(strlen($shipped))->toBeGreaterThan(100000, 'The built script read is far too small to be this application, so every name below would report as missing.');

    $orphans = [];

    foreach (['store' => $named['stores'], 'magic' => $named['magics']] as $kind => $found) {
        foreach ($found as $name => $templates) {
            foreach ($templates as $template) {
                $ownScript = (string) file_get_contents(RepoTree::root().'/'.$template);

                if (alpineRegistersInScript($name, $shipped, $kind) || alpineRegistersInScript($name, $ownScript, $kind)) {
                    continue;
                }

                $orphans[] = $template.' → '.($kind === 'store' ? '$store.'.$name : '$'.$name.'()');
            }
        }
    }

    sort($orphans);

    expect($orphans)->toBe(
        [],
        "A store nothing registered reads back as undefined and a magic nothing registered is not defined at\n".
        "all: Alpine logs one expression error and the handler, the class binding or the text simply does\n".
        "nothing. Nothing is thrown, the page returns 200, and the only trace is in a console no test opens.\n".
        "The three registries are separate, so the name has to be registered as the kind the template reads\n".
        "it as. Register it in resources/js and rebuild — the script under public/build is read as it is.\n  ".implode("\n  ", $orphans),
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

// The rules above accept a registration wherever they find one, and ask only
// whether the name is there. This is the other half of that acceptance: WHEN
// the script hands it over. A provider registered from an event that has
// already fired is as absent as one nobody wrote.
//
// Both kinds of script are read. The shape was forbidden in a template while
// resources/js/lock.js registered the lock store from an `alpine:init`
// listener and nowhere else — not because that was allowed, but because no
// rule opened the file to ask.
it('has every provider a script of its own registers reachable without a second alpine:init', function (): void {
    $registered = alpineProviderRegistrationSites();
    $kinds = array_count_values(array_column($registered, 'kind'));

    expect($kinds['template'] ?? 0)->toBeGreaterThan(
        0,
        'No template registers an Alpine provider in a <script> of its own, so the rule above accepts a path '
        .'nothing takes and this one holds nothing to the clock. Either the reader stopped, or the acceptance '
        .'in the message above excuses a shape the tree no longer has.'
    );

    expect($kinds['module'] ?? 0)->toBeGreaterThan(
        5,
        'Only '.($kinds['module'] ?? 0).' registrations were read out of resources/js, which is fewer than app.js '
        .'alone makes — so the front-end half of this rule is reading nothing and the file that shipped this '
        .'defect would pass again.'
    );

    $late = [];

    foreach ($registered as $site) {
        if (alpineRegistersOnlyOnInit($site['script'])) {
            $late[] = $site['where'].' → '.$site['name'];
        }
    }

    sort($late);

    expect($late)->toBe(
        [],
        "These are registered from an `alpine:init` listener and nowhere else. Alpine dispatches that event\n".
        "once, from Alpine.start(); wire:navigate re-runs a page's body scripts on arrival and never restarts\n".
        "Alpine, so the first reader to reach the page through a navigate link registers a listener for\n".
        "something that has already happened. The x-data then binds an empty scope — one expression error, a\n".
        "200, and every method absent; a store binds nothing at all and every expression reading it is\n".
        "undefined. Register eagerly off `window.Alpine` and keep the listener as the fallback for the page\n".
        "that loads before Alpine exists, the way resources/js/app.js and resources/js/lock.js do.\n  ".implode("\n  ", $late),
    );
});

it('reads a front-end module and a template <script> as the same kind of registration site', function (): void {
    $sites = alpineProviderRegistrationSites();
    $named = static fn (string $where): array => array_values(array_unique(
        array_column(array_filter($sites, static fn (array $site): bool => $site['where'] === $where), 'name')
    ));

    expect($named('resources/js/app.js'))->toContain('palette', 'overlay', 'plural');
    expect($named('resources/js/lock.js'))->toBe(['beatraxLock'], 'the store this rule was widened for is one of the sites it now reads');
    expect(array_column($sites, 'kind'))->toContain('template', 'module');
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

it('reads a store and a magic out of an expression, and leaves what is not one alone', function (): void {
    $named = static fn (string $element, string $attribute, string $value): array => alpineRegistryNamesIn($element, $attribute, $value);

    expect($named('button', 'x-on:click', '$store.overlay.add("drawer")')['stores'])->toBe(['overlay'], 'a store is read wherever an expression reaches for one, not only in x-data');
    expect($named('span', 'x-text', '$plural(arms, "k", n)')['magics'])->toBe(['plural'], 'a magic is a call the page cannot make unless somebody registered it');
    expect($named('button', 'x-on:click', '$wire.$set("a", 1)')['magics'])->toBe([], 'the $set Livewire hangs off $wire is reached through a dot and names no magic');
    expect($named('div', 'x-init', '$dispatch("open")')['magics'])->toBe([], 'Alpine registers its own magics, so reading one as missing reports the framework as broken');
    expect($named('x-ledger::amount', ':money', '$rowSecondary($row)')['magics'])->toBe([], 'a colon prop on a Blade component tag is PHP the compiler evaluates, never JavaScript');
    expect($named('span', 'x-text', '"{{ $money($row) }}"')['magics'])->toBe([], 'a call inside a Blade echo is PHP: the browser is handed the result and never the call');
    expect($named('div', 'class', '$store.overlay.blocking ? 1 : 2')['stores'])->toBe([], 'a plain class attribute is a class list, not an expression Alpine evaluates');
});

it('asks the registry a template reads, not merely whether the name is written somewhere', function (): void {
    expect(alpineRegistersInScript('overlay', "Alpine.store('overlay', { names: [] });", 'store'))->toBeTrue();
    expect(alpineRegistersInScript('overlay', "Alpine.data('overlay', () => ({}));", 'store'))->toBeFalse('a factory of that name is not reachable as $store.overlay');
    expect(alpineRegistersInScript('plural', "Alpine.magic('plural', () => 1);", 'magic'))->toBeTrue();
    expect(alpineRegistersInScript('plural', "Alpine.store('plural', {});", 'magic'))->toBeFalse('a store of that name is not reachable as $plural()');
});
