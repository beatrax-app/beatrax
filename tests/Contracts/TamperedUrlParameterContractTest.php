<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Route;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Modules\Ledger\Models\Account;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-query-parameter-a-reader-can-retype
 */

/**
 * Whether a file binds a Livewire property to the query string. Named rather
 * than written inline so the control at the foot of this file drives the same
 * reader the walk drives: an attribute renamed, a comment left unstripped or a
 * pattern that stopped matching all read as "no components bind a parameter",
 * which is the same answer as a tree where none of them can be tampered with.
 */
function tamperedUrlBindsAQueryParameter(string $source): bool
{
    // The codebase's own prose names the attribute — this file does — so an
    // unstripped comment reads as a binding.
    $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? $source;

    return preg_match('/#\[Url\b[^\n]*\]\s*public\s/', $stripped) === 1;
}

/**
 * @return list<string>
 */
function urlBoundComponentFiles(): array
{
    $files = [];

    $directory = new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS);

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator($directory) as $file) {
        $path = $file->getPathname();

        // Modules/ and not the tree: a Livewire component is a class under a
        // module's Http/Livewire directory by construction, and the suite's own
        // doubles bind #[Url] to prove a rule rather than to ship a screen.
        if (! $file->isFile() || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
            continue;
        }

        if (! tamperedUrlBindsAQueryParameter((string) file_get_contents($path))) {
            continue;
        }

        $files[] = str_replace(base_path().'/', '', $path);
    }

    sort($files);

    return $files;
}

// Assembled from the file rather than written out, because a test in
// tests/ naming a module's Internal namespace is itself a boundary
// violation — BoundaryArchTest bans the inline form outright.
function urlBoundComponentClass(string $relativePath): ?string
{
    $source = (string) file_get_contents(base_path($relativePath));

    if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
        return null;
    }

    if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $class) !== 1) {
        return null;
    }

    $candidate = trim($namespace[1]).'\\'.$class[1];

    return class_exists($candidate) && is_subclass_of($candidate, Component::class)
        ? $candidate
        : null;
}

// A page route names its component as the action, so the pairing is read off
// the router rather than listed here. Parameterised URIs are left out: their
// segment is a different reader-supplied surface from the query string.
/**
 * @return array<string, string>
 */
function urlBoundComponentRoutes(): array
{
    $routes = [];

    /** @var Route $route */
    foreach (app('router')->getRoutes() as $route) {
        $uri = $route->uri();

        if (! in_array('GET', $route->methods(), true) || str_contains($uri, '{')) {
            continue;
        }

        $action = $route->getActionName();

        if (! isset($routes[$action])) {
            $routes[$action] = '/'.ltrim($uri, '/');
        }
    }

    return $routes;
}

/**
 * The property paths a date picker binds anywhere in the tree, so this arm
 * discovers its own subjects instead of listing them: a `#[Url]` property whose
 * name is also bound to `<x-core::date-input>` is a date the address bar can
 * carry. Blade comments are stripped, since the goals modal names the component
 * inside one.
 *
 * @return list<string>
 */
function tamperedUrlDateProperties(): array
{
    $names = [];
    $directory = new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS);

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator($directory) as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! str_ends_with($path, '.blade.php')) {
            continue;
        }

        $source = PatternScan::replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

        foreach (MarkupSource::elements($source, 'x-core::date-input') as $element) {
            foreach ($element->attributes() as $name => $model) {
                if ($name === 'wire:model' || str_starts_with($name, 'wire:model.')) {
                    $names[] = $model;
                }
            }
        }
    }

    sort($names);

    return array_values(array_unique($names));
}

// A date the reader cannot have typed: the picker only ever emits Y-m-d, so
// each of these arrives from a bookmark, a hand-edited URL or a stale link.
// None of them failed to parse — every one produced a different, usable date,
// or a string a DATE comparison then read one character at a time.
/**
 * @return array<string, string>
 */
function tamperedUrlMalformedDates(): array
{
    return [
        'a bare year' => '2026',
        'a bare year-month' => '2026-07',
        'an unpadded day' => '2026-1-5',
        'a day February does not have' => '2026-02-29',
        'a relative English date' => 'tomorrow',
        'a day with a time after it' => '2026-07-01 13:45:00',
    ];
}

/**
 * @return array<string, mixed>
 */
function tamperedUrlShapes(): array
{
    return [
        // A vocabulary the component does not name, and one belonging to a
        // neighbouring parameter: both arrive as an ordinary word.
        'an unknown word' => 'bogus',
        'a neighbouring vocabulary' => 'anomaly',
        'nothing at all' => '',

        // A scalar where a list is expected and the reverse, plus the two
        // array shapes a query string can build that no rail ever sends.
        'a list of non-numeric members' => ['bogus'],
        'a list mixing an id with junk' => ['1', 'bogus'],
        'a nested list' => [['1']],
        'a keyed array' => ['key' => 'value'],
        'a list holding null' => [null],

        // Numbers no row can hold, and one no column can.
        'a negative number' => '-1',
        'zero' => '0',
        'a number past every column width' => '999999999999999999999',
        'a fractional number' => '1.5',

        // Bytes a keyboard does not send and a date that is not one.
        'a NUL byte mid-string' => "ab\x00cd",
        'seven characters that are not a year-month' => 'abcdefg',
        'an impossible calendar date' => '2026-02-31',
    ];
}

function tamperedUrlOffender(string $relativePath, string $parameter, string $description, Throwable $thrown): string
{
    $root = $thrown;
    while ($root->getPrevious() !== null) {
        $root = $root->getPrevious();
    }

    // The origin, not just the message: half of these read "Undefined array
    // key 0" or "must be of type int", which name no file, and the site is
    // often a compiled Blade view rather than the component itself.
    $origin = str_replace(base_path().'/', '', $root->getFile()).':'.$root->getLine();

    if (str_contains($origin, 'storage/framework/views/')) {
        $compiled = (string) file_get_contents($root->getFile());
        if (preg_match('#/\*\*PATH (.+?) ENDPATH\*\*/#', $compiled, $source) === 1) {
            $origin .= ' (compiled from '.str_replace(base_path().'/', '', $source[1]).')';
        }
    }

    return sprintf(
        '%s  ?%s= %s  →  %s: %s  at %s',
        $relativePath,
        $parameter,
        $description,
        $root::class,
        str_replace("\n", ' ', substr($root->getMessage(), 0, 120)),
        $origin,
    );
}

// An empty table short-circuits several of these pages before the arm the
// parameter selects is ever reached, so a junk value reads as handled on a
// surface that would still throw with one row behind it. Every id this
// returns is a real row, which is also what the cross-user arm needs.
/**
 * @return array{userId: int, accountId: int, categoryId: int, counterpartyId: int, scenarioId: int, marker: string}
 */
function seedTamperedUrlUser(string $marker): array
{
    $user = User::query()->create([
        'username' => 'tampered-url-'.$marker.'-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
        'default_currency_view' => 'eur_only',
    ]);

    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => $marker.' Account',
        'slug' => 'tampered-url-'.$marker.'-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00TUP'.strtoupper(bin2hex(random_bytes(6))),
        'default_currency' => 'EUR',
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    $categoryId = (int) $connection->table('categories')->insertGetId([
        'user_id' => $user->id,
        'name' => $marker.' Category',
        'slug' => 'tampered-url-cat-'.strtolower($marker).'-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $counterpartyId = (int) $connection->table('counterparties')->insertGetId([
        'user_id' => $user->id,
        'type' => 'merchant',
        'slug' => 'tampered-url-cp-'.strtolower($marker).'-'.bin2hex(random_bytes(3)),
        'display_name' => $marker.' Counterparty',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scenarioId = (int) $connection->table('forecast_scenarios')->insertGetId([
        'user_id' => $user->id,
        'name' => $marker.' Scenario',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $runId = $connection->table('import_runs')->insertGetId([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/tampered-url-'.strtolower($marker).'.csv',
        'sha256' => hash('sha256', 'tampered-url-run-'.$marker),
        'uploaded_at' => now(),
        'status' => 'committed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $connection->table('transactions')->insert([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'import_run_id' => $runId,
        'category_id' => $categoryId,
        'counterparty_id' => $counterpartyId,
        'type' => 'expense',
        'posted_at' => CarbonImmutable::now()->startOfMonth()->addDay()->toDateString(),
        'booked_at' => now(),
        'value_date' => now()->toDateString(),
        'amount_minor' => -4_200,
        'currency' => 'EUR',
        'settled_amount_minor' => -4_200,
        'settled_currency' => 'EUR',
        'counterparty_name' => $marker.' Counterparty',
        'counterparty_normalized' => strtolower($marker).'-counterparty',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'tampered-url-tx-'.$marker),
        'fingerprint_version' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $connection->table('notifications')->insert([
        'id' => hash('sha256', 'tampered-url-notification-'.$marker),
        'user_id' => $user->id,
        'state' => 'open',
        'title' => $marker.' Notification',
        'body' => $marker.' notification body',
        'params' => json_encode(['target_kind' => 'dashboard']),
        'trigger_type' => 'digest',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'userId' => (int) $user->id,
        'accountId' => (int) $account->id,
        'categoryId' => $categoryId,
        'counterpartyId' => $counterpartyId,
        'scenarioId' => $scenarioId,
        'marker' => $marker,
    ];
}

beforeEach(function (): void {
    $this->tamperReader = seedTamperedUrlUser('Reader');
    $this->tamperNeighbour = seedTamperedUrlUser('Neighbour');

    test()->actingAs(User::query()->findOrFail($this->tamperReader['userId']));
});

it('renders every component that binds a query parameter, whatever the parameter says', function (): void {
    $offenders = [];
    $undrivable = [];
    $ungated = [];
    $refused = [];
    $driven = [];

    foreach (urlBoundComponentFiles() as $relativePath) {
        $component = urlBoundComponentClass($relativePath);

        if ($component === null) {
            $undrivable[] = $relativePath.' — no Livewire component class resolved from the file';

            continue;
        }

        $parameters = [];

        foreach ((new ReflectionClass($component))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(Url::class);

            if ($attributes === []) {
                continue;
            }

            $parameters[] = $attributes[0]->newInstance()->as ?? $property->getName();
        }

        if ($parameters === []) {
            $undrivable[] = $relativePath.' — the file declares #[Url] but reflection found no bound property';

            continue;
        }

        // A page route answering 200 with no query string at all is the
        // baseline this arm compares against; one that does not is gated on
        // something the fixture has not turned on, and is named below rather
        // than passed over, since only the component arm then covers it.
        $uri = urlBoundComponentRoutes()[$component] ?? null;
        if ($uri !== null && test()->get($uri)->getStatusCode() !== 200) {
            $ungated[] = $relativePath.' — '.$uri.' does not answer 200 unparameterised in this fixture';
            $uri = null;
        }

        foreach ($parameters as $parameter) {
            foreach (tamperedUrlShapes() as $description => $value) {
                try {
                    Livewire::withQueryParams([$parameter => $value])->test($component);
                    $driven[] = $relativePath.'?'.$parameter;
                } catch (Throwable $thrown) {
                    $offenders[] = tamperedUrlOffender($relativePath, $parameter, $description, $thrown);
                }

                if ($uri === null) {
                    continue;
                }

                try {
                    $status = test()->get($uri.'?'.http_build_query([$parameter => $value]))->getStatusCode();
                    $driven[] = $uri.'?'.$parameter;

                    if ($status >= 500) {
                        $offenders[] = sprintf(
                            '%s  GET %s?%s= %s  →  HTTP %d',
                            $relativePath,
                            $uri,
                            $parameter,
                            $description,
                            $status,
                        );
                    } elseif ($status >= 400) {
                        $refused[] = sprintf('GET %s?%s= %s → HTTP %d', $uri, $parameter, $description, $status);
                    }
                } catch (Throwable $thrown) {
                    $offenders[] = tamperedUrlOffender($relativePath, 'GET '.$uri.'?'.$parameter, $description, $thrown);
                }
            }
        }
    }

    expect($undrivable)->toBe([], implode("\n", [
        'A component binds a query parameter but this guard could not drive it, so it',
        'is covered in name only. Fix the discovery rather than narrowing the scan —',
        'a guard that reads as complete while skipping half the components is the',
        'failure this file exists to prevent:',
        ...$undrivable,
    ]));

    // Pinned in both directions: a route dropping out of reach of this arm has
    // to be a visible diff, or the HTTP half quietly shrinks while the test
    // keeps reading as complete. Each of these is still driven as a component.
    expect($ungated)->toBe([
        'Modules/DevMode/Internal/Http/Livewire/ArtisanRunnerPage.php — /dev/artisan does not answer 200 unparameterised in this fixture',
        'Modules/DevMode/Internal/Http/Livewire/AuditLogPage.php — /dev/audit does not answer 200 unparameterised in this fixture',
        'Modules/DevMode/Internal/Http/Livewire/LogTailerPage.php — /dev/logs does not answer 200 unparameterised in this fixture',
    ], implode("\n", [
        'The set of page routes this arm cannot reach has changed. Developer-mode',
        'pages sit behind a flag the fixture does not set; anything else here is a',
        'page that stopped answering, which is a defect rather than an exemption.',
        ...$ungated,
    ]));

    // A 4xx is a deliberate answer, not a crash, so the set is pinned in both
    // directions — a page that starts refusing a junk parameter has to be a
    // visible diff rather than something this arm absorbs. Empty because every
    // page now soft-resets a junk id; see the doc named in the message below.
    sort($refused);

    expect($refused)->toBe([], implode("\n", [
        'The set of query parameters answered with a 4xx has changed. Refusing is a',
        'decision a page is allowed to make, but only on purpose: a page that starts',
        'refusing a value it used to render is a dead end the reader reaches from a',
        'bookmark, and one that stops refusing has given up a check somebody wrote.',
        'The rule this set encodes is .docs/features/forecasting/url-parameters.md.',
        ...$refused,
    ]));

    expect($offenders)->toBe([], implode("\n", [
        'A #[Url] property is reader-supplied: anything can arrive in the address bar,',
        'including a value from a neighbouring vocabulary, a list where a scalar goes,',
        'and bytes no keyboard sends. An unknown value from a URL is a bad link, so',
        'coerce it to the default at the boundary — tryFrom() ?? default(), a numeric',
        'filter over an id list, a shape check before a date parse. Rejecting a bad',
        'STORED value is a different decision and stays where it is.',
        '',
        ...$offenders,
    ]));

    // The denominator, after the verdicts because a drive that raised is
    // counted as an offender rather than as a drive. Every arm above reports
    // the same clean tree when the discovery returns nothing, so the number of
    // parameters actually driven is asserted rather than assumed.
    expect(count($driven))->toBeGreaterThan(
        100,
        'Only '.count($driven).' parameter drives completed across the whole walk, which is what a broken '
        .'discovery looks like rather than a tree with almost nothing bound to the address bar.'
    );
});

// The loud failure is a 500. The quiet one returns 200: a parameter naming a
// row that belongs to somebody else, coerced into a filter that answers with
// their data. Every id below is a real row of the neighbour's, so a component
// that scopes by user renders none of it and one that forgets renders the name.
it('answers with none of a neighbouring reader\'s rows when a parameter names one', function (): void {
    $neighbour = $this->tamperNeighbour;
    $marker = $neighbour['marker'];

    $named = [
        'account id' => $neighbour['accountId'],
        'category id' => $neighbour['categoryId'],
        'counterparty id' => $neighbour['counterpartyId'],
        'scenario id' => $neighbour['scenarioId'],
        'user id' => $neighbour['userId'],
    ];

    $leaks = [];
    $checked = [];

    foreach (urlBoundComponentFiles() as $relativePath) {
        $component = urlBoundComponentClass($relativePath);

        if ($component === null) {
            continue;
        }

        foreach ((new ReflectionClass($component))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(Url::class);

            if ($attributes === []) {
                continue;
            }

            $parameter = $attributes[0]->newInstance()->as ?? $property->getName();

            foreach ($named as $description => $id) {
                // Both shapes, because an id list and a scalar id reach
                // different halves of the same filter.
                foreach ([(string) $id, [(string) $id]] as $value) {
                    try {
                        $html = Livewire::withQueryParams([$parameter => $value])->test($component)->html();
                    } catch (Throwable) {
                        // The crash arm above owns throwing; this one owns
                        // what a page that answers 200 puts on the screen.
                        continue;
                    }

                    $checked[] = $relativePath.'?'.$parameter;

                    if (str_contains($html, $marker)) {
                        $leaks[] = sprintf(
                            '%s  ?%s= the neighbour\'s %s (%d)%s',
                            $relativePath,
                            $parameter,
                            $description,
                            $id,
                            is_array($value) ? ' as a list' : '',
                        );
                    }
                }
            }
        }
    }

    expect($leaks)->toBe([], implode("\n", [
        'A query parameter named a row belonging to another reader and that row\'s',
        'name reached this reader\'s screen. Coercing an out-of-vocabulary parameter',
        'is not enough on its own: where the value is a row id, the query behind it',
        'has to re-check ownership and answer with nothing rather than with theirs.',
        '',
        ...$leaks,
    ]));

    expect(count($checked))->toBeGreaterThan(
        20,
        'Only '.count($checked).' parameter/id pairs were rendered, so this arm read almost nothing and '
        .'would report a clean screen for a page it never mounted.'
    );
});

// The loud failure is a 500 and the leaky one is a neighbour's row. The third
// is neither: a 200 listing a DIFFERENT set of the reader's own rows because a
// parameter that is not a date was applied as though it were. `?before=2026`
// bound the string '2026' against a DATE column, compared it a character at a
// time, and answered with an empty list under a chip still reading "Before
// 2026" and a badge still counting one active filter.
//
// The doc's rule for an unknown URL value is to coerce it to the default, so
// the assertion is equality with the page carrying no parameter at all: same
// rows, and none of the reader's own missing.
it('lists the same rows for a date parameter that is not a date as for no parameter at all', function (): void {
    $marker = $this->tamperReader['marker'];
    $dateProperties = tamperedUrlDateProperties();

    $narrowed = [];
    $checked = [];

    foreach (urlBoundComponentFiles() as $relativePath) {
        $component = urlBoundComponentClass($relativePath);

        if ($component === null) {
            continue;
        }

        foreach ((new ReflectionClass($component))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $attributes = $property->getAttributes(Url::class);

            if ($attributes === [] || ! in_array($property->getName(), $dateProperties, true)) {
                continue;
            }

            $parameter = $attributes[0]->newInstance()->as ?? $property->getName();

            foreach (tamperedUrlMalformedDates() as $description => $value) {
                // Counted, not merely looked for: the fixture's name is on the
                // account and counterparty chips as well as on the row, so a
                // page listing nothing still SAYS 'Reader'. The baseline is
                // re-taken with the query string explicitly emptied, because
                // withQueryParams() persists onto the request and the previous
                // parameter was still narrowing it.
                try {
                    $html = Livewire::withQueryParams([$parameter => $value])->test($component)->html();
                    $baseline = substr_count(Livewire::withQueryParams([])->test($component)->html(), $marker);
                } catch (Throwable) {
                    // The crash arm owns throwing; this one owns what a page
                    // that answers 200 puts on the screen.
                    continue;
                }

                $checked[] = $relativePath.'?'.$parameter.'='.$value;

                $found = substr_count($html, $marker);

                if ($found !== $baseline) {
                    $narrowed[] = sprintf(
                        '%s  ?%s= %s (%s)  →  names the reader\'s own rows %d time(s); with no parameter at all, %d',
                        $relativePath,
                        $parameter,
                        $value,
                        $description,
                        $found,
                        $baseline,
                    );
                }
            }
        }
    }

    expect($narrowed)->toBe([], implode("\n", [
        'A query parameter that is not a date changed which of the reader\'s own rows',
        'the page listed, and said nothing. That is a 200 nobody can debug: the list',
        'is wrong, the chip prints the value back, and the only signal is rows that',
        'are not there. Coerce the parameter to the default at the boundary — a shape',
        'check before the date parse — or refuse it and say so.',
        '',
        ...$narrowed,
    ]));

    // Discovered rather than listed, so a picker added to a #[Url] property
    // joins this arm on its own; an empty walk means the discovery broke.
    expect(count($dateProperties))->toBeGreaterThan(
        5,
        'The Blade walk found '.count($dateProperties).' properties bound to a date picker, which is what a '
        .'renamed component or a broken attribute read looks like rather than a tree that stopped offering dates.'
    );

    expect($checked)->not->toBe(
        [],
        'No date parameter was driven at all. The arm intersects the pickers above with the bound '
        .'properties, so an empty intersection means one of the two discoveries stopped answering and this '
        .'rule is reporting a clean list it never built.'
    );
});

it('finds the components by walking the tree rather than from a list', function (): void {
    $files = urlBoundComponentFiles();

    // Pinning the count would rot into a number nobody reads. What the scan
    // must not do is quietly shrink — a renamed attribute, a moved directory
    // or a broken regex all read as "no offenders" otherwise, and the floor
    // sits far under today's count so only a broken walk trips it.
    expect(count($files))->toBeGreaterThan(
        5,
        'The walk found '.count($files).' components binding a query parameter, which is too few to be this '
        .'tree. Every arm in this file is read off that list.'
    );

    $resolved = array_values(array_filter(
        array_map(urlBoundComponentClass(...), $files),
    ));

    expect(count($resolved))->toBe(count($files), sprintf(
        "Every file the scan finds must resolve to a Livewire component it can mount.\n"
        ."Found %d file(s), resolved %d.\n  %s",
        count($files),
        count($resolved),
        implode("\n  ", $files),
    ));
});

// The discovery is what every arm above is read off, and it is a pattern over
// source: a renamed attribute, an unstripped comment or a lost `\b` all answer
// "no component binds a parameter", which is the same answer as a tree with
// nothing tamperable in it. Driven here against a string rather than against
// the tree, so a rewrite of the reader cannot quietly stop finding them.
it('reads a bound query parameter and none of the shapes that only look like one', function (string $source, bool $bound): void {
    expect(tamperedUrlBindsAQueryParameter($source))->toBe($bound);
})->with([
    'a bound property' => ["<?php\nclass C { #[Url] public string \$mode = 'a'; }", true],
    'a property bound under another name' => ["<?php\nclass C { #[Url(as: 'q')] public string \$mode = 'a'; }", true],
    'the attribute named in a comment' => ["<?php\nclass C { // #[Url] public string \$mode = 'a';\n public string \$other = 'b'; }", false],
    'a non-public property carrying it' => ["<?php\nclass C { #[Url] protected string \$mode = 'a'; }", false],
    'a different Livewire attribute' => ["<?php\nclass C { #[Computed] public function mode(): string { return 'a'; } }", false],
]);
