<?php

declare(strict_types=1);

use Modules\Community\Public\Dto\SupportResource;
use Modules\Community\Public\Services\SupportResourceProvider;

it('resolves a merchant resource by brand prefix despite legal-entity noise', function (): void {
    $resource = app(SupportResourceProvider::class)->forCounterparty('Spotify AB', 'merchant');

    expect($resource)->not->toBeNull();
    expect($resource?->name)->toBe('Spotify');
    expect($resource?->cancelUrl)->toContain('spotify.com');
});

it('does not resolve across the wrong type', function (): void {
    expect(app(SupportResourceProvider::class)->forCounterparty('Spotify', 'government'))->toBeNull();
});

it('resolves a government agency with help + phone resources', function (): void {
    $resource = app(SupportResourceProvider::class)->forCounterparty('Belastingdienst', 'government');

    expect($resource)->not->toBeNull();
    expect($resource?->type)->toBe('government');
    expect($resource?->helpUrl)->not->toBeNull();
    expect($resource?->phone)->not->toBeNull();
});

it('returns null for an unknown counterparty', function (): void {
    expect(app(SupportResourceProvider::class)->forCounterparty('Totally Unknown Merchant Ltd', 'merchant'))->toBeNull();
});

it('builds a mailto: href with the cancellation request pre-filled', function (): void {
    $resource = new SupportResource(
        name: 'Example',
        type: 'merchant',
        cancelEmailTo: 'opzeggen@example.com',
        cancelEmailSubject: 'Opzegging abonnement',
        cancelEmailBody: 'Hierbij zeg ik mijn abonnement op.',
    );

    $href = $resource->mailtoHref();

    expect($href)->toStartWith('mailto:opzeggen@example.com?');
    expect($href)->toContain('subject=Opzegging%20abonnement');
    expect($href)->toContain('body=');
});

it('has no mailto when there is no cancellation email channel', function (): void {
    $resource = new SupportResource(name: 'Example', type: 'merchant', cancelUrl: 'https://example.com/cancel');

    expect($resource->mailtoHref())->toBeNull();
    expect($resource->hasAny())->toBeTrue();
});

it('does not match a different brand that merely shares a leading substring', function (): void {
    expect(app(SupportResourceProvider::class)->forCounterparty("Applebee's", 'merchant'))->toBeNull();
});

it('does not match a base-brand charge to a Premium-tier resource', function (): void {
    $provider = app(SupportResourceProvider::class);
    // The real corpus carries an "Albert Heijn Premium" entry.
    expect($provider->forCounterparty('Albert Heijn', 'merchant'))->toBeNull();
    expect($provider->forCounterparty('Albert Heijn Premium', 'merchant'))->not->toBeNull();
});

it('refuses to build a mailto from an address carrying injection characters', function (): void {
    $resource = new SupportResource(
        name: 'Evil',
        type: 'merchant',
        cancelEmailTo: 'opzeggen@vendor.com?bcc=harvest@evil.com',
        cancelEmailSubject: 'x',
    );

    expect($resource->mailtoHref())->toBeNull();
});
