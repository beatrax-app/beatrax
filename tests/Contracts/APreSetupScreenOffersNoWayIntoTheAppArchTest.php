<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Public\Recovery\PendingRecoveryCodes;
use Modules\Core\Models\User;
use Modules\Core\Public\Navigation\PreSetupSurface;
use Modules\Core\Public\Support\PatternScan;
use Modules\Core\Public\Support\RenderedMarkup;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-pre-setup-screen-renders-the-application-shell
 */

// How each withheld surface is reached, and — for the two this Composer root
// cannot route — why it is not. Keyed by route name so the arm below can hold
// the map to the enum: a surface added to one and not the other is a screen
// nothing renders, which is how a sweep goes quietly inert.
/**
 * @return array<string, array{signedIn: bool, owedCodes: bool, uri: ?string, unreachable: string}>
 */
function preSetupReach(): array
{
    return [
        'desktop.setup' => ['signedIn' => true, 'owedCodes' => false, 'uri' => '/setup', 'unreachable' => ''],
        'desktop.welcome' => ['signedIn' => false, 'owedCodes' => false, 'uri' => '/welcome', 'unreachable' => ''],
        'signup' => ['signedIn' => false, 'owedCodes' => false, 'uri' => '/signup', 'unreachable' => ''],
        'auth.recovery-codes-display' => ['signedIn' => true, 'owedCodes' => true, 'uri' => '/recovery-codes', 'unreachable' => ''],
        'auth.change-password' => ['signedIn' => true, 'owedCodes' => false, 'uri' => '/change-password', 'unreachable' => ''],
        'setup' => ['signedIn' => true, 'owedCodes' => false, 'uri' => '/setup-wizard', 'unreachable' => ''],
        'mobile.welcome' => ['signedIn' => false, 'owedCodes' => false, 'uri' => null, 'unreachable' => 'the desktop first-launch gate answers it with a redirect to /welcome; the phone shell is where it renders'],
        'mobile.import' => ['signedIn' => true, 'owedCodes' => false, 'uri' => '/mobile/import', 'unreachable' => ''],
        'mobile.restore' => ['signedIn' => false, 'owedCodes' => false, 'uri' => null, 'unreachable' => 'it refuses unless the account table is empty, and an empty one is the state the desktop gate redirects out of'],
        'mobile.pair' => ['signedIn' => true, 'owedCodes' => false, 'uri' => '/mobile/pair', 'unreachable' => ''],
        'mobile.setup' => ['signedIn' => true, 'owedCodes' => false, 'uri' => '/mobile/setup', 'unreachable' => ''],
        'mobile.setup.done' => ['signedIn' => true, 'owedCodes' => false, 'uri' => '/mobile/setup/done', 'unreachable' => ''],
        'mobile.database-incomplete' => ['signedIn' => true, 'owedCodes' => false, 'uri' => '/mobile/database-incomplete', 'unreachable' => ''],
    ];
}

// The shell layout mounts eight components; the floor sits under that and above
// zero, so a reader that stopped matching fails here rather than reporting every
// one of them accounted for.
const PRE_SETUP_SHELL_MOUNT_FLOOR = 5;

/**
 * @return list<array{0: string, 1: string}>
 */
function preSetupRenderable(): array
{
    $rows = [];

    foreach (preSetupReach() as $routeName => $reach) {
        if ($reach['uri'] !== null) {
            $rows[] = [$routeName, $reach['uri']];
        }
    }

    return $rows;
}

function preSetupResponse(string $routeName): TestResponse
{
    $reach = preSetupReach()[$routeName];

    if ($reach['signedIn']) {
        /** @var User $user */
        $user = User::query()->create([
            'username' => 'pre-setup-'.bin2hex(random_bytes(4)),
            'password' => 'fixture',
            'period_start_day' => 1,
            'default_currency_view' => 'eur_only',
        ]);

        test()->actingAs($user);
    }

    $session = $reach['owedCodes'] ? [PendingRecoveryCodes::SESSION_KEY => ['ABCD-EFGH-JKLM-NPQR-STUV']] : [];

    return test()->withSession($session)->get((string) ($reach['uri'] ?? '/'));
}

/** @return list<string> the Livewire components a rendered page mounts */
function preSetupMounts(RenderedMarkup $page): array
{
    $names = [];

    foreach ($page->all('[wire\\:snapshot]') as $node) {
        $snapshot = json_decode((string) $node->attribute('wire:snapshot'), true);

        if (is_array($snapshot) && is_array($snapshot['memo'] ?? null) && is_string($snapshot['memo']['name'] ?? null)) {
            $names[] = $snapshot['memo']['name'];
        }
    }

    return $names;
}

// Every way into the application that the shell draws, at BOTH widths: the
// drawer is the static sidebar from 1024px up and the top bar is CSS-hidden
// there, so a rule that looked for one of the pair passed on the width nobody
// had opened. The palette is named by its mount rather than by a glyph.
/** @return list<string> */
function preSetupWaysIn(RenderedMarkup $page): array
{
    $mounts = preSetupMounts($page);

    $found = array_filter([
        'aside.side (the sidebar)' => $page->has('aside.side'),
        'header.top-bar (the phone menubar)' => $page->has('header.top-bar'),
        '.side-search (the sidebar search box)' => $page->has('.side-search'),
        'role="search"' => $page->has('[role="search"]'),
        'x-on:keydown.window (the palette keybind)' => $page->has('[x-on\\:keydown\\.window]'),
        'dev.command-palette-modal' => in_array('dev.command-palette-modal', $mounts, true),
        'search.palette-search-endpoint' => in_array('search.palette-search-endpoint', $mounts, true),
    ]);

    return array_keys($found);
}

it('withholds the shell from exactly the surfaces it can name a route for', function (): void {
    /** @var Router $router */
    $router = app('router');

    $declared = array_map(static fn (PreSetupSurface $case): string => $case->value, PreSetupSurface::cases());
    $mapped = array_keys(preSetupReach());

    sort($declared);
    sort($mapped);

    expect($declared)->not->toBe([], 'PreSetupSurface declares no surface at all, so every case here compares two empty lists.')
        ->and($mapped)->toBe($declared, implode("\n", [
            'Every surface the layout withholds the shell from needs a row in preSetupReach()',
            'saying how a test reaches it, or the sweep below never visits it and goes green',
            'on a page it never rendered.',
        ]));

    $missing = array_values(array_filter($declared, static fn (string $name): bool => ! $router->has($name)));

    expect($missing)->toBe([], implode("\n", [
        'These route names are withheld from the shell but no longer exist:',
        ...$missing,
        '',
        'A renamed route silently stops matching, and the screen it names gets the',
        'menubar back with nothing failing.',
    ]));
});

it('draws no menubar and no search on a screen a reader reaches before the app is theirs', function (string $routeName, string $uri): void {
    $response = preSetupResponse($routeName);

    expect($response->getStatusCode())->toBe(200, $uri.' answered '.$response->getStatusCode().', so this rule read no page at all.');

    $waysIn = preSetupWaysIn(RenderedMarkup::of((string) $response->getContent()));

    expect($waysIn)->toBe([], implode("\n", [
        $uri.' offers a way into an application the reader has not got yet:',
        ...$waysIn,
        '',
        'layouts.app draws the menubar and the palette behind AppShellVisibility,',
        'not behind @auth: the whole of signup, the recovery-code hand-over, the',
        'migration splash and the phone provisioning happen signed in. Add the',
        'route to PreSetupSurface rather than hiding the controls in the page.',
    ]));
})->with(preSetupRenderable());

// A wire:snapshot is a bearer token for the component it names, so a screen
// mounting the application's modals hands out their endpoints whether or not it
// draws a control for them.
/** @return list<string> */
function preSetupShellMachinery(): array
{
    return [
        'core.system-alerts-banner',
        'categorization.rule-form-modal',
        'receipts.receipt-conflict-toast',
        'community.suggest-mapping-modal',
        'dev.command-arg-prompt-modal',
    ];
}

/**
 * @return array<string, string> component => why the sweep does not look for it
 */
function preSetupShellMountsAnsweredElsewhere(): array
{
    return [
        'dev.command-palette-modal' => 'preSetupWaysIn() already reads it, as one of the seven ways into the app',
        'search.palette-search-endpoint' => 'preSetupWaysIn() already reads it, as one of the seven ways into the app',
        'email-scan.oauth-client-wizard-modal' => 'the wizard shell mounts its own copy on purpose, and a rendered page cannot say which shell put it there',
    ];
}

it('mounts none of the application machinery the shell supplies to a page inside it', function (string $routeName, string $uri): void {
    $mounted = preSetupMounts(RenderedMarkup::of((string) preSetupResponse($routeName)->getContent()));
    $offenders = array_values(array_intersect(preSetupShellMachinery(), $mounted));

    expect($offenders)->toBe([], implode("\n", [
        $uri.' mounts components the reader has no application to use them from:',
        ...$offenders,
        '',
        'On the migration splash they also query tables the pending migrations',
        'have not created. A first-run screen that needs a modal mounts it itself.',
    ]));
})->with(preSetupRenderable());

// The list above is a list, and a list cannot see a component added beside the
// ones it names. Read against the layout that mounts them, so the eighth arrives
// here rather than in nobody's scan.
it('accounts for every component the shell layout mounts', function (): void {
    $layout = base_path('resources/views/layouts/app.blade.php');

    $mounted = PatternScan::all(
        '/@livewire\(\s*\'([^\']+)\'/',
        PatternScan::replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($layout)),
    )[1];

    expect(count($mounted))->toBeGreaterThan(
        PRE_SETUP_SHELL_MOUNT_FLOOR,
        'The reader found '.count($mounted).' mounts in the shell layout, so the check below compares two lists '
        .'against nothing.'
    );

    $unaccounted = array_values(array_diff(
        $mounted,
        preSetupShellMachinery(),
        array_keys(preSetupShellMountsAnsweredElsewhere()),
    ));

    expect($unaccounted)->toBe([], implode("\n", [
        'The shell mounts these and no rule here says what a pre-setup screen does about them:',
        ...$unaccounted,
        '',
        'A wire:snapshot is a bearer token, so a component the shell supplies is one a',
        'withheld screen must not hand out. Add it to preSetupShellMachinery(), or to',
        'preSetupShellMountsAnsweredElsewhere() with the reason another rule covers it.',
    ]));
});

// The control the sweep above needs. Every marker it looks for is absent from a
// page that failed to render at all, so without an ordinary page proving the
// same markers are present the rule would pass loudest when the shell broke
// everywhere.
it('still draws both on a page inside the application', function (): void {
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'pre-setup-control',
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // Not the dashboard: an account with no ledger behind it is redirected
    // from there to the import page, and a redirect body carries none of the
    // markers either.
    $page = RenderedMarkup::of((string) test()->actingAs($user)->get('/settings')->assertOk()->getContent());

    expect(preSetupWaysIn($page))->toBe([
        'aside.side (the sidebar)',
        'header.top-bar (the phone menubar)',
        '.side-search (the sidebar search box)',
        'role="search"',
        'x-on:keydown.window (the palette keybind)',
        'dev.command-palette-modal',
        'search.palette-search-endpoint',
    ], 'An ordinary page no longer draws the shell, so the sweep above proves nothing.');
});

// Taking the top bar away takes the strip it reserved and painted with it. The
// stylesheet zeroes .safe-screen's top inset for a document that HAS a bar, so
// a page may wear the class unconditionally — but a page wearing neither draws
// its own heading under the status bar on a phone.
it('reserves the system-bar seam on every surface it takes the top bar from', function (string $routeName, string $uri): void {
    $page = RenderedMarkup::of((string) preSetupResponse($routeName)->getContent());

    expect($page->has('.safe-screen') || $page->has('.wiz-page'))->toBeTrue(implode("\n", [
        $uri.' draws no bar above it and reserves no seam of its own.',
        '',
        'resources/css/app.css defines .safe-screen as the four insets, and zeroes',
        'the top one again wherever a .top-bar is in the document. The wizard shell',
        'is the other answer: .wiz-page paints both edges because it scrolls past',
        'them.',
    ]));
})->with(preSetupRenderable());

it('leaves the two phone-shell surfaces this Composer root cannot route answering a redirect rather than a page', function (): void {
    $offenders = [];

    foreach (preSetupReach() as $routeName => $reach) {
        if ($reach['uri'] !== null) {
            continue;
        }

        $status = preSetupResponse($routeName)->getStatusCode();

        if ($status < 300 || $status >= 400) {
            $offenders[] = $routeName.' answered '.$status.', not a redirect — '.$reach['unreachable'];
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'A surface the sweep skips has started answering with a page of its own:',
        ...$offenders,
        '',
        'Give it a uri in preSetupReach() so the sweep renders it, rather than',
        'leaving a rendered screen nothing here looks at.',
    ]));
});

// The forced-change guard exempts sign-out on purpose, and the sidebar used to
// be the only place that control existed. Withholding the menubar from this
// screen takes the exemption's one affordance with it unless the page carries
// its own.
it('offers a way out of the forced password change once the menubar is gone', function (): void {
    $page = RenderedMarkup::of((string) preSetupResponse('auth.change-password')->getContent());

    $actions = array_map(
        static fn (RenderedMarkup $form): ?string => $form->attribute('action'),
        $page->all('form[method="POST"]'),
    );

    // in_array rather than toContain: a second argument to toContain is another
    // needle, not a message, so the explanation would be asserted as markup and
    // the rule would fail against a page that is correct.
    expect(in_array(route('logout'), $actions, true))->toBeTrue(implode("\n", [
        'The change-password page has no sign-out on it, and no menubar to borrow',
        'one from. A partner who will not set a password here cannot leave.',
    ]));
});
