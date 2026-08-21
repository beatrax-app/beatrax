<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Import\Public\Services\MerchantNameResolver;

// desktop-04, reproduced on an SM-S928B against a clean install: the ASN demo
// statement's `Albert Heijn 1042` rendered as "Albert" on the import preview —
// the Czech chain at resources/corpus/merchants/cz.yaml:11. The account had NO
// country set, which is what skipping the signup selector leaves you with, so
// the every-region fallback was live. This seeds the SHIPPED corpus rather than
// a fixture, because the defect was in how the real files sort against each
// other: CorpusLoader sorts filenames, so cz.yaml seeds before nl.yaml.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'shipped-corpus-fresh-install',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($this->user->id));
});

it('seeds the shipped corpus with both Albert rows, cz before nl', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $cz = $db->connection()->table('community_merchant_mappings')->where('pattern', 'ALBERT')->first(['id', 'region']);
    $nl = $db->connection()->table('community_merchant_mappings')->where('pattern', 'ALBERT HEIJN')->first(['id', 'region']);

    expect($cz?->region)->toBe('CZ');
    expect($nl?->region)->toBe('NL');
    expect((int) $cz?->id)->toBeLessThan((int) $nl?->id);
});

it('resolves the device statement line to Albert Heijn on a fresh install with no country set', function (): void {
    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('Albert Heijn 1042', (int) $this->user->id))->toBe('Albert Heijn');
});

it('resolves the fuller descriptor the same way', function (): void {
    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('Betaalautomaat Albert Heijn 1042 Amsterdam', (int) $this->user->id))
        ->toBe('Albert Heijn');
});

it('still narrows to the Czech chain for a genuinely Czech line', function (): void {
    /** @var MerchantNameResolver $resolver */
    $resolver = $this->app->make(MerchantNameResolver::class);

    expect($resolver->resolve('ALBERT 4321 PRAHA', (int) $this->user->id))->toBe('Albert');
});
