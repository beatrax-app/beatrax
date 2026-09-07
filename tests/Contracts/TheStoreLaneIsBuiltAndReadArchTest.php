<?php

declare(strict_types=1);

// A store lane that exists in the config and nowhere in CI is a plan. This
// holds the two halves that make it real: something builds the mas target, and
// something reads what it built.

function storeLaneWorkflow(): string
{
    foreach (['.github/workflows', '../.github/workflows'] as $candidate) {
        $path = base_path($candidate.'/release-mac-app-store.yml');

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

it('has a workflow that builds the store target', function (): void {
    expect(storeLaneWorkflow())->not->toBe('');
    expect(storeLaneWorkflow())->toContain('--mac mas');
});

// Dispatch, not tag. The store build needs a provisioning profile that lives
// in the Apple Developer portal, and a release blocked by paperwork is a
// direct-download channel that stops shipping for a store listing it does not
// depend on.
it('is dispatched rather than triggered by a release', function (): void {
    $workflow = storeLaneWorkflow();

    expect($workflow)->toContain('workflow_dispatch');
    expect($workflow)->not->toContain('tags:');
});

it('refuses to build without the profile the store signs against', function (): void {
    expect(storeLaneWorkflow())->toContain('MAS_PROVISIONING_PROFILE_BASE64 is empty');
});

// base64 of an empty secret decodes to an empty file without complaining, and
// an empty profile fails much later as a signing error nobody can place.
it('reads the decoded profile back rather than trusting the pipe', function (): void {
    expect(storeLaneWorkflow())->toContain('! -s build/embedded.provisionprofile');
});

it('carries no updater, which the store forbids', function (): void {
    expect(storeLaneWorkflow())->toContain('NATIVEPHP_UPDATER_ENABLED=false');
});

// The config is a claim and the bundle is the evidence.
it('reads the bundle it built for what review refuses', function (): void {
    expect(storeLaneWorkflow())->toContain('desktop:review-mac-bundle');
});

// A find that matched nothing and a bundle with no findings print the same
// thing, and only one of them means the build produced anything.
it('refuses a run that found no bundle to read', function (): void {
    expect(storeLaneWorkflow())->toContain('nothing was read, so nothing is proven');
});
