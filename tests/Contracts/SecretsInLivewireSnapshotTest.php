<?php

declare(strict_types=1);

use Livewire\Component;
use Modules\Auth\Internal\Http\Livewire\AddUserPage;
use Modules\Auth\Internal\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Http\Livewire\ResetPasswordPage;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Core\Public\Services\SecretsColumnRegistry;
use Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal;
use Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap;
use Tests\Contracts\Fixtures\Livewire\SyntheticListenerViolator;
use Tests\Contracts\Fixtures\Livewire\SyntheticPublicPropertyViolator;
use Tests\Contracts\Fixtures\Livewire\SyntheticQueryStringViolator;

/**
 * Public-release defensive arch invariant — no Livewire component
 * inside production code may name a secret-bearing column on any
 * surface that gets serialised into the wire snapshot.
 *
 * Livewire serialises every public property, every `$listeners` key,
 * and every `$queryString` entry into the payload that travels back to
 * the browser. A user with browser devtools can read those payloads;
 * any secret-column data that lands there is effectively published.
 *
 * The check is reflection-driven: every concrete subclass of
 * `Livewire\Component` discovered under `Modules/.../Http/Livewire/`
 * (Internal or Public) is walked via `ReflectionClass`. For every
 * public property name and every default `$listeners` / `$queryString`
 * array value, the test asks:
 *
 *   - Does the property name (snake_case OR camelCase) contain the
 *     bare column name from any registry entry?
 *   - Does the listener key / queryString string EXACTLY equal a
 *     registry entry?
 *
 * If yes, the (FQCN, property) pair lands in the violations list
 * unless it appears in the explicit `$allowList`. Every entry in the
 * allow-list carries an inline rationale that names the specific
 * user-input flow it represents.
 *
 * The allow-list is the deliberate carve-out for forms where the
 * USER types their own secret (a login password, a partner-account
 * password being set, an OAuth client_secret being pasted into the
 * BYO-client wizard). The wire snapshot in those flows carries
 * exactly what the user typed into the form — not stored data the
 * app would otherwise have kept private — so the snapshot exposure
 * is isomorphic to the form already showing in the browser.
 */
it('exposes the documented secret columns via SecretsColumnRegistry::columns()', function (): void {
    $columns = SecretsColumnRegistry::columns();

    expect($columns)->toContain('oauth_secrets.access_token');
    expect($columns)->toContain('oauth_secrets.refresh_token');
    expect($columns)->toContain('oauth_secrets.client_secret');
    expect($columns)->toContain('users.password');
    expect($columns)->toContain('users.remember_token');
    expect($columns)->toContain('user_recovery_codes.code_hash');
});

it('keeps the static accessor and the DI-shim instance method in sync', function (): void {
    $registry = new SecretsColumnRegistry;

    expect($registry->all())->toBe(SecretsColumnRegistry::columns());
});

it('does not allow production Livewire components to expose registry columns via public properties, listeners, or queryString (noSecretsInLivewireSnapshot)', function (): void {
    /** @var array<class-string, list<string>> $allowList */
    $allowList = [
        // FQCN => list of allowed property names / listener keys /
        // queryString entries (all checked as strings against the
        // matchers below).
        //
        // Every entry must name a user-input field where the value
        // serialised into the wire snapshot is the user's own input,
        // not stored data the app would otherwise keep private.
        LoginPage::class => [
            // `$password` binds the password the user is typing into
            // the sign-in field. The wire snapshot carries the same
            // characters the browser DOM already shows; no stored
            // credential is exposed.
            'password',
        ],
        SignupPage::class => [
            // `$password` + `$passwordConfirmation` capture the
            // password the first-launch user is choosing. Same
            // reasoning as LoginPage — user input, not stored value.
            'password',
            'passwordConfirmation',
        ],
        ChangePasswordPage::class => [
            // `$currentPassword` + `$newPassword` + `$newPasswordConfirmation`
            // collect the change-password form input. The hashed
            // version reaches the DB only after the action method
            // runs; the wire snapshot mirrors form state, not stored
            // data.
            'currentPassword',
            'newPassword',
            'newPasswordConfirmation',
        ],
        ManageUserPage::class => [
            // `$newPartnerPassword` is the password the admin sets
            // on behalf of the partner; the partner is forced to
            // change it on next sign-in. User-input field, not a
            // stored-secret echo.
            'newPartnerPassword',
        ],
        AddUserPage::class => [
            // `$initialPassword` + `$initialPasswordConfirmation`
            // capture the admin-typed initial password for a newly-
            // created partner. The partner is forced to change it on
            // first sign-in. User-input field, not stored data.
            'initialPassword',
            'initialPasswordConfirmation',
        ],
        ResetPasswordPage::class => [
            // `$newPassword` + `$newPasswordConfirmation` collect the
            // recovery-code-driven password reset input. The page
            // clears the fields after any error so plaintext never
            // re-enters the snapshot — user-input only.
            'newPassword',
            'newPasswordConfirmation',
        ],
        OAuthClientWizardModal::class => [
            // `$clientSecret` is the BYO-OAuth client secret the user
            // pastes into the wizard. The wire snapshot carries form
            // input, not a value pulled from oauth_secrets — the
            // OAuthSecretsRepository hands plaintext to the file
            // sink only after submit() validates.
            'clientSecret',
        ],
        AppLockSettingsSection::class => [
            // `$accountPassword` is the account password the user
            // re-types to confirm a security downgrade (disabling the
            // app lock / forgot-PIN re-wrap, D-23). The wire snapshot
            // carries the user's own form input, not stored data.
            'accountPassword',
        ],
        MobileImportBootstrap::class => [
            // `$password` + `$passwordConfirmation` capture the account
            // the first-user is CREATING on the mobile import device —
            // the same user-input reasoning as SignupPage (the value is
            // handed to SignupAction, never an echo of stored data). The
            // component additionally zeroes both properties (and `$pin`)
            // the moment submit() consumes them so the plaintext never
            // lingers in a later wire snapshot; the retry path re-reads
            // from a server-side-only session stash, never these fields.
            'password',
            'passwordConfirmation',
        ],
    ];

    $registryColumns = SecretsColumnRegistry::columns();

    // Build the lookup forms once: { 'access_token', 'accessToken',
    // 'refresh_token', 'refreshToken', 'client_secret', 'clientSecret',
    // 'password', 'remember_token', 'rememberToken', 'code_hash',
    // 'codeHash' }. Tiny set; allocation cost is irrelevant.
    $columnNameForms = [];
    foreach ($registryColumns as $fqColumn) {
        $bare = explode('.', $fqColumn)[1] ?? $fqColumn;
        $columnNameForms[] = $bare;
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $bare))));
        if ($camel !== $bare) {
            $columnNameForms[] = $camel;
        }
    }
    $columnNameForms = array_values(array_unique($columnNameForms));

    $componentClasses = discoverLivewireComponentClassesUnderModules();

    $hits = [];
    foreach ($componentClasses as $fqcn) {
        $reflection = new ReflectionClass($fqcn);
        if ($reflection->isAbstract()) {
            continue;
        }
        $allowedForThisClass = $allowList[$fqcn] ?? [];

        // ── public properties ────────────────────────────────────────
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            // Only flag properties DECLARED on this class or one of
            // its non-framework ancestors — Livewire base classes
            // themselves declare nothing offensive. `getDeclaringClass()`
            // is the right filter because reflection enumerates the
            // full inheritance chain.
            $declaring = $property->getDeclaringClass()->getName();
            if (! str_starts_with($declaring, 'Modules\\')) {
                continue;
            }
            $name = $property->getName();
            foreach ($columnNameForms as $form) {
                if (stripos($name, $form) !== false) {
                    if (in_array($name, $allowedForThisClass, true)) {
                        break;
                    }
                    $hits[] = "{$fqcn}::\${$name} (matches column form '{$form}')";
                    break;
                }
            }
        }

        // ── $listeners + $queryString default values ─────────────────
        foreach (['listeners', 'queryString'] as $surface) {
            if (! $reflection->hasProperty($surface)) {
                continue;
            }
            $property = $reflection->getProperty($surface);
            if ($property->getDeclaringClass()->getName() !== $fqcn) {
                // Only check overrides on the concrete component, not
                // framework-supplied defaults.
                continue;
            }
            $defaults = $reflection->getDefaultProperties();
            $value = $defaults[$surface] ?? null;
            if (! is_array($value)) {
                continue;
            }

            // For both listeners and queryString, every key (for
            // listeners) and every value (for queryString) is a
            // string. Check both shapes uniformly.
            $stringsToCheck = [];
            foreach ($value as $k => $v) {
                if (is_string($k)) {
                    $stringsToCheck[] = $k;
                }
                if (is_string($v)) {
                    $stringsToCheck[] = $v;
                }
            }
            foreach ($stringsToCheck as $entry) {
                if (in_array($entry, $registryColumns, true)) {
                    if (in_array($entry, $allowedForThisClass, true)) {
                        continue;
                    }
                    $hits[] = "{$fqcn}::\${$surface} contains registry entry '{$entry}'";
                }
            }
        }
    }

    expect($hits)->toBe(
        [],
        "Production Livewire components must not name secrets-tagged columns on any wire-snapshot surface. Offenders:\n  ".implode("\n  ", $hits),
    );
});

it('catches synthetic violators living outside the production tree', function (): void {
    $registryColumns = SecretsColumnRegistry::columns();
    $bareNames = array_map(
        static fn (string $fq): string => explode('.', $fq)[1] ?? $fq,
        $registryColumns,
    );
    $columnNameForms = $bareNames;
    foreach ($bareNames as $bare) {
        $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $bare))));
        if ($camel !== $bare) {
            $columnNameForms[] = $camel;
        }
    }

    // SyntheticPublicPropertyViolator declares `$oauthAccessToken` —
    // that string contains the camelCase form `accessToken`.
    $reflectionA = new ReflectionClass(SyntheticPublicPropertyViolator::class);
    $matchA = false;
    foreach ($reflectionA->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
        foreach ($columnNameForms as $form) {
            if (stripos($p->getName(), $form) !== false) {
                $matchA = true;
                break 2;
            }
        }
    }
    expect($matchA)->toBeTrue();

    // SyntheticListenerViolator declares a listener key that exactly
    // equals the registry entry `oauth_secrets.refresh_token`.
    $reflectionB = new ReflectionClass(SyntheticListenerViolator::class);
    $defaultsB = $reflectionB->getDefaultProperties();
    $listenerKeys = array_keys($defaultsB['listeners'] ?? []);
    expect($listenerKeys)->toContain('oauth_secrets.refresh_token');
    expect($registryColumns)->toContain('oauth_secrets.refresh_token');

    // SyntheticQueryStringViolator declares a queryString string that
    // exactly equals the registry entry `oauth_secrets.client_secret`.
    $reflectionC = new ReflectionClass(SyntheticQueryStringViolator::class);
    $defaultsC = $reflectionC->getDefaultProperties();
    $queryStringValues = $defaultsC['queryString'] ?? [];
    expect($queryStringValues)->toContain('oauth_secrets.client_secret');
    expect($registryColumns)->toContain('oauth_secrets.client_secret');
});

/**
 * Discover every concrete `Livewire\Component` subclass under
 * `Modules/...Http/Livewire/`. Uses a filesystem walk + FQCN derivation
 * rather than `composer dump-autoload --classmap-authoritative` so the
 * test stays self-contained.
 *
 * @return list<class-string<Component>>
 */
function discoverLivewireComponentClassesUnderModules(): array
{
    $modulesRoot = base_path('Modules');
    if (! is_dir($modulesRoot)) {
        return [];
    }

    $classes = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesRoot, RecursiveDirectoryIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $path = $file->getPathname();
        if (! str_ends_with($path, '.php')) {
            continue;
        }
        if (preg_match('#/Http/Livewire/#', $path) !== 1) {
            continue;
        }
        if (str_contains($path, '/tests/') || str_contains($path, '/Tests/')) {
            continue;
        }

        // Derive FQCN from the path:
        // Modules/Foo/Internal/Http/Livewire/Bar.php
        // → Modules\Foo\Internal\Http\Livewire\Bar
        $relative = str_replace($modulesRoot.DIRECTORY_SEPARATOR, '', $path);
        $relative = preg_replace('/\.php$/', '', $relative) ?? $relative;
        $fqcn = 'Modules\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

        if (! class_exists($fqcn)) {
            continue;
        }
        $reflection = new ReflectionClass($fqcn);
        if (! $reflection->isSubclassOf(Component::class)) {
            continue;
        }
        /** @var class-string<Component> $fqcn */
        $classes[] = $fqcn;
    }

    sort($classes);

    return $classes;
}
