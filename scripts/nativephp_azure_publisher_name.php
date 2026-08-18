#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Adds the `publisherName` key NativePHP omits from `win.azureSignOptions`.
 *
 * Why this is needed:
 *
 *   electron-builder 26 declares `publisherName` REQUIRED on
 *   `WindowsAzureSigningConfiguration` (app-builder-lib `scheme.json`:
 *   required = certificateProfileName, codeSigningAccountName, endpoint,
 *   publisherName). NativePHP's published `electron-builder.mjs` emits
 *   only the first three, so any build with Azure Trusted Signing
 *   configured aborts during config validation:
 *
 *     configuration.win.azureSignOptions misses the property 'publisherName'
 *
 *   The build fails loudly, which is the safe direction — but it means
 *   Trusted Signing cannot be used at all on electron-builder 26 without
 *   this patch.
 *
 * What the value must be:
 *
 *   The certificate subject's common name, which for Trusted Signing is
 *   the legal entity name from the approved Azure identity validation.
 *   It is NOT free text: electron-builder passes it to signtool, and a
 *   value that does not match the certificate is rejected at sign time.
 *
 * Why it joins the existing all-or-nothing guard:
 *
 *   NativePHP only emits `azureSignOptions` when every Azure variable is
 *   set. Extending that condition rather than defaulting the new key
 *   keeps the same property: either the block is complete and signing
 *   happens, or the block is absent entirely. A defaulted publisherName
 *   would produce a config that validates and then fails at signtool,
 *   which is a worse failure than not signing.
 *
 * The patch is reapplied before every build (it is a `prebuild` hook)
 * and is idempotent: a config that already names publisherName is left
 * untouched.
 *
 * Exit codes:
 *   0  patched, or already correct, or nothing to patch
 *   1  the config exists but does not have the expected shape
 */
$projectRoot = dirname(__DIR__);
$configPath = $projectRoot.'/nativephp/electron/electron-builder.mjs';

if (! is_file($configPath)) {
    fwrite(STDERR, "nativephp_azure_publisher_name: no electron-builder.mjs found at {$configPath} — has `php artisan native:install --publish` run?\n");

    exit(1);
}

$source = file_get_contents($configPath);

if ($source === false) {
    fwrite(STDERR, "nativephp_azure_publisher_name: could not read {$configPath}\n");

    exit(1);
}

if (str_contains($source, 'publisherName')) {
    fwrite(STDOUT, "nativephp_azure_publisher_name: publisherName already present, leaving as-is.\n");

    exit(0);
}

$replacements = [
    'the azureCodeSigningAccountName declaration' => [
        '/(const\s+azureCodeSigningAccountName\s*=\s*process\.env\.NATIVEPHP_AZURE_CODE_SIGNING_ACCOUNT_NAME;)/',
        "$1\nconst azurePublisherName = process.env.NATIVEPHP_AZURE_PUBLISHER_NAME;",
    ],
    'the azureSignOptions guard condition' => [
        '/(\.\.\.\(azureEndpoint\s*&&\s*azureCertificateProfileName\s*&&\s*azureCodeSigningAccountName)/',
        '$1 && azurePublisherName',
    ],
    'the codeSigningAccountName option' => [
        '/(codeSigningAccountName:\s*azureCodeSigningAccountName,)/',
        "$1\n                      publisherName: azurePublisherName,",
    ],
];

foreach ($replacements as $label => [$pattern, $replacement]) {
    $source = preg_replace($pattern, $replacement, $source, 1, $count);

    if ($source === null || $count !== 1) {
        fwrite(STDERR, "nativephp_azure_publisher_name: could not locate {$label} in {$configPath}\n");

        exit(1);
    }
}

if (file_put_contents($configPath, $source) === false) {
    fwrite(STDERR, "nativephp_azure_publisher_name: could not write {$configPath}\n");

    exit(1);
}

fwrite(STDOUT, "nativephp_azure_publisher_name: added publisherName to win.azureSignOptions.\n");

exit(0);
