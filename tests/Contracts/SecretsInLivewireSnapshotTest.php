<?php

declare(strict_types=1);

use Livewire\Component;
use Modules\Auth\Internal\Http\Livewire\AddUserPage;
use Modules\Auth\Internal\Http\Livewire\ChangePasswordPage;
use Modules\Auth\Internal\Http\Livewire\LoginPage;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Auth\Internal\Http\Livewire\ResetPasswordPage;
use Modules\Auth\Internal\Http\Livewire\SignupPage;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Public\Http\Livewire\DeleteAccountSection;
use Modules\Core\Public\Services\SecretsColumnRegistry;
use Modules\EmailScan\Public\Http\Livewire\OAuthClientWizardModal;
use Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap;
use Tests\Contracts\Fixtures\Livewire\SyntheticListenerViolator;
use Tests\Contracts\Fixtures\Livewire\SyntheticPublicPropertyViolator;
use Tests\Contracts\Fixtures\Livewire\SyntheticQueryStringViolator;

/**
 * @link ../../.docs/architecture/livewire-snapshot-secrets.md
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
        // An entry is justified only when the snapshot value is what the user
        // just typed into the form, never an echo of a stored secret.
        LoginPage::class => [
            'password',
        ],
        SignupPage::class => [
            'password',
            'passwordConfirmation',
        ],
        ChangePasswordPage::class => [
            'currentPassword',
            'newPassword',
            'newPasswordConfirmation',
        ],
        ManageUserPage::class => [
            // The partner is forced to change it at next sign-in.
            'newPartnerPassword',
        ],
        AddUserPage::class => [
            // The partner is forced to change it at first sign-in.
            'initialPassword',
            'initialPasswordConfirmation',
        ],
        ResetPasswordPage::class => [
            // The page clears both fields after any error, so a failed attempt
            // leaves no plaintext in the next snapshot.
            'newPassword',
            'newPasswordConfirmation',
        ],
        OAuthClientWizardModal::class => [
            // Pasted, never read back: OAuthSecretsRepository receives the
            // plaintext only after submit() validates.
            'clientSecret',
        ],
        DeleteAccountSection::class => [
            // cancel() zeroes it when the confirmation is abandoned.
            'password',
        ],
        AppLockSettingsSection::class => [
            'accountPassword',
        ],
        MobileImportBootstrap::class => [
            // submit() zeroes both (and `$pin`) the moment it consumes them; the
            // retry path re-reads from a server-side session stash instead.
            'password',
            'passwordConfirmation',
        ],
    ];

    $registryColumns = SecretsColumnRegistry::columns();

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
            // Only properties declared under Modules\ — reflection walks the whole
            // inheritance chain, and Livewire's own base classes declare nothing.
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
                continue;
            }
            $defaults = $reflection->getDefaultProperties();
            $value = $defaults[$surface] ?? null;
            if (! is_array($value)) {
                continue;
            }

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

    // `$oauthAccessToken` matches because it contains the camelCase form
    // `accessToken` — the property matcher is a substring test.
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

    $reflectionB = new ReflectionClass(SyntheticListenerViolator::class);
    $defaultsB = $reflectionB->getDefaultProperties();
    $listenerKeys = array_keys($defaultsB['listeners'] ?? []);
    expect($listenerKeys)->toContain('oauth_secrets.refresh_token');
    expect($registryColumns)->toContain('oauth_secrets.refresh_token');

    $reflectionC = new ReflectionClass(SyntheticQueryStringViolator::class);
    $defaultsC = $reflectionC->getDefaultProperties();
    $queryStringValues = $defaultsC['queryString'] ?? [];
    expect($queryStringValues)->toContain('oauth_secrets.client_secret');
    expect($registryColumns)->toContain('oauth_secrets.client_secret');
});

// A filesystem walk rather than a classmap dump keeps the test self-contained.
/**
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
